# Adapt different API contracts inside Yii

Keep the old client contract in Yii. The backend can keep its new API.
Use inline mappings for request and response translations. Application callbacks handle
complex contracts through the same mapping pipeline.

- [One fluent proxy](#one-fluent-proxy)
- [Share a controller contract](#share-a-controller-contract)
- [Advanced: adapt a different envelope](#advanced-adapt-a-different-envelope)
- [Advanced: replace a mapping stage](#advanced-replace-a-mapping-stage)
- [Preserve errors and headers](#preserve-errors-and-headers)
- [Keep routing and security explicit](#keep-routing-and-security-explicit)
- [Enable the feature after checking compatibility](#enable-the-feature-after-checking-compatibility)
- [Advanced: explicit query and resource contracts](#advanced-explicit-query-and-resource-contracts)
- [File uploads](#file-uploads)

## One fluent proxy

The examples use a flat catalog with backend fields `id` and `name`.

Without mappings, Strangler forwards the request and returns the backend response
without translating its JSON contract. Use that mode when the two APIs already match.

Use `uppercase()` when the client expects uppercase attributes and the success
envelope shown below. Describe only naming differences:

```php
use Cosmira\Strangler\Strangler;

return Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->fields(['name' => 'TITLE'])
    ->get('view', '/items/{id}')
    ->put('edit', '/items/{id}')
    ->build();
```

Ordinary request fields, filters and ordering names are lowercase upstream;
ordinary response attributes are uppercase in Yii. `with` becomes `include`, and
`start/limit` becomes `page/per_page`. There are no required field, writable, filter
or sort lists. Keep native request/access checks before Strangler and validate the
backend independently: naming conventions do not grant permissions.

For a backend item `{"data":{"id":42,"name":"Desk"}}`, this returns
`{"state":"data","data":{"ITEM_ID":42,"TITLE":"Desk"}}`.
The convention reads records under `data`; list responses also require `meta.total`.
It uses HTTP 200 for mapped success responses. See [response defaults](#response-defaults)
for empty actions and custom envelopes. This convention maps successful responses;
[error contracts](#preserve-errors-and-headers) require separate configuration.

`response(fields: [...])` maps upstream to legacy names. `request(fields: [...])` maps legacy to
upstream names. A hypothetical backend may return `label` while accepting `name`.
A request-only alias does not change filter or sort names.

### Share field names across directions

Use `fields()` for one upstream-to-Yii name map shared by request bodies,
responses, filters, ordering and validation field names:

```php
Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->fields(['name' => 'TITLE'])
    ->get('get', '/items')
    ->put('edit', '/items/{id}')
    ->build();
```

`TITLE` is sent as `name` in writes, filters and ordering;
`name` is returned as `TITLE`. Ordinary attributes keep automatic
case conversion. The map changes names, not values or payload structure.

`request(fields: ['TITLE' => 'input_name'])` overrides the write name only.
`response(fields: ['name' => 'OUTPUT_NAME'])` overrides the response name.
Neither override changes the shared query name. Directional overrides take
precedence regardless of fluent call order. Existing directional declarations
remain supported.

### Identifiers in routes and bodies

`uppercase('ITEM_ID')` maps the response's `id` to `ITEM_ID`. For a route containing
`{id}`, it also reads `ITEM_ID` from the original body when the request identifier
is absent or empty. The conventional payload mapper removes `ITEM_ID` from the body
sent upstream.

For the `edit` action above, a body of:

```json
{"ITEM_ID":42,"DISPLAY_NAME":"Desk"}
```

selects `PUT /items/42` and sends:

```json
{"display_name":"Desk"}
```

A nonempty request identifier takes precedence over the body identifier. Check
conflicting identifiers in the [preceding input filter](integration.md#write-action-validation).
This routing rule does not rename `filter[ITEM_ID]` to `filter[id]`; query aliases
remain independent of the response identifier.

### Directional query differences

A search parameter can have different semantics from the model attribute:

```php
use Cosmira\Strangler\Strangler;

return Strangler::proxy('directory')
    ->uppercase('PERSON_ID')
    ->query(
        filters: ['display_name' => 'query'],
        sorts: ['title' => 'name'],
    )
    ->get('get', '/people')
    ->build();
```

The first name is the field after ordinary request-name conversion; the second
is the name sent in that query direction. These exceptions affect only their query direction. They do not rename write
attributes or response fields. Ordinary filters and sorts still need no list.
`query(includes: ['groups' => 'groups_all'])` declares a relation-name difference;
ordinary undeclared names still pass through the convention. Multiple legacy names
may share one upstream include; an empty target omits that include.

Native scalar `with` flags select their associative keys: an encoded
`with[IDENTITY_NAME]=true` selects `IDENTITY_NAME`, so
`query(includes: ['IDENTITY_NAME' => 'identity'])` forwards `include=identity`.
False scalar flags also select by key presence. List strings keep their existing
meaning; malformed containers and unsupported nested selections retain native execution.
Filter and sort values do not use this include-only normalization.

`query(values: [...])` formats declared filter values after ordinary names and list
values have been normalized. Formatter keys use upstream filter names. For example,
expand a historic name while retaining both spellings for the backend:

```php
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->query(values: [
        'kind' => Format::expandWith(['legacy' => ['modern']]),
    ])
    ->get('get', '/api/items')
    ->build();
```

`filter[KIND]=legacy,custom` becomes `filter[kind]=legacy,modern,custom`.
`Format::expand()` preserves unknown names and order, removes duplicate names,
and leaves non-string input unchanged. It does not select native execution based
on what the backend supports.

For application-specific query semantics, `query(using: ...)` receives the original
query, its context and the already configured `Query`. Validate or normalize the
semantic difference, then invoke that supplied mapper. Do not rebuild `Fields` or
`Query::legacy()` in the callback. Return `false` to retain Yii before HTTP.
`mapQuery()` remains the explicit full-replacement escape hatch and takes priority
over the convention and its semantic adapter.

`query(nativeOn: ['query'])` keeps nonempty native full-text searches in Yii before
model resolution. This is a semantic exception, not a backend capability list.
`request(nativeContains: ['csv' => '\\"'], actions: ['import'])` keeps matching
string payloads in Yii; absent and non-string values do not match.

`request(using: ...)` provides the corresponding payload extension. Its callback
receives the remaining payload, the original context, and the compiled convention.
Call the supplied convention's `payload($payload)` method to retain automatic
input naming before applying an application-specific structural change. A full
`mapPayload()` callback replaces both that convention and its semantic adapter.

### Body values in routes

```php
Strangler::proxy('directory')
    ->uppercase('PERSON_ID')
    ->post('create', '/groups/{group}/people', fromBody: [
        'group' => ['GROUPS.*.GROUP_ID', 'GROUPS.*.PARENT_GROUP_ID'],
    ])
    ->build();
```

A wildcard accepts exactly one list element. Alternatives may be absent; present
alternatives must be valid and agree. Missing, invalid or ambiguous values retain
native execution before HTTP. Route parameters do not become query/body fields.
Consumed leaves and newly empty ancestors disappear from the forwarded body;
sibling metadata remains. Query/payload callbacks keep the original body in their
context, while the payload argument contains the remaining body.

### Compute parameters and bodies per route

Use a closure when route parameters need application logic or a database lookup:

```php
->put(
    'move',
    '/people/{id}/groups',
    parameters: static fn (array $query, array $payload): array => [
        'id' => $query['id'] ?? $payload['PERSON_ID'],
    ],
    body: static fn (array $payload): array => [
        'ids' => [$payload['PARENT_GROUP_ID']],
    ],
)
```

`parameters` receives the original query and body. It runs only for the selected
route, after preceding Yii filters and request guards, before HTTP. Returned
values are URL parameters; they do not remove or rename body fields. Return
`false` to keep Yii execution before transport. Missing, invalid or conflicting
resolved values do not reach the backend. Database exceptions are not fallback
signals.

A route `body` closure receives the original body. Return the changed structure;
shared names, request value conversions, nested record conversions and identifier
removal apply automatically afterwards. Return `false` to keep Yii before HTTP.

```php
body: static function (array $payload): array {
    unset($payload['GROUPS']);

    return $payload;
},
```

Without a naming convention, the returned structure is sent unchanged. Existing
array body declarations select their complete upstream structure as before.
No callback causes retries or native fallback after an upstream attempt.

This changes the callback contract from 5.5: remove the supplied mapper argument
and return the structure directly. These semantics are available in 6.0.0.

### Change query and response structures

Use ordinary PHP for differences beyond field names. A query modifier receives
the original query and `['action' => ...]`. Field names, filters, sorting and
pagination are translated after it returns. Returning `false` keeps Yii before HTTP.

```php
->query(modify: static function (array $query, array $context): array {
    $query['with'] = ['contacts'];

    return $query;
})
```

A response body modifier runs on decoded success JSON before automatic record
mapping. Its context contains `action` and the upstream HTTP `status`.
Return the modified JSON or a PSR response for an application-specific result. An exception after HTTP never
replays the native action.

```php
->response(body: static function (mixed $body, array $context): mixed {
    if ($context['action'] === 'copy') {
        return new \GuzzleHttp\Psr7\Response(200,
            ['Content-Type' => 'application/json'], '{"state":"data"}');
    }

    return $body;
})
```

These modifiers are available in 6.0.0. `fields()` remains the shared naming
map for input, output, filters and sorting; modifiers describe structural differences.

### Select the outgoing body

Use `body` when an operation changes the payload structure:

```php
Strangler::proxy('directory')
    ->using(new ApiContract())
    ->put('move', '/people/{id}/groups', body: ['ids' => ['PARENT_GROUP_ID']])
    ->build();
```

`'note' => 'NOTE'` copies one value; `'ids' => ['PARENT_GROUP_ID']` builds a list.
Source names are literal native keys. Missing keys produce `null`. Only declared
fields are sent; `body: []` sends an empty payload. Omitting `body` keeps normal
mapping. Callbacks still receive the original payload snapshot.

For create-only defaults and scalar-to-list aliases:

```php
->request(
    lists: ['PARENT_ID' => 'parent_ids'],
    defaults: ['is_visible' => true],
    actions: ['create'],
)
```

List aliases wrap present, non-null values. Null keeps its ordinary field name.
Defaults fill missing upstream fields and preserve explicit null or false.
Other request aliases remain active; conflicting aliases keep execution in Yii.

### Value differences and scoped identifiers

`request(values: [...])` and `response(values: [...])` accept attribute-to-formatter maps keyed by
upstream names. They run only for fields present in that input or record. Input
formatters run after input names are translated; output formatters run before
response names are translated. Opaque values are not recursively renamed.

For example, serialize an input attribute while decoding the same output attribute:

```php
Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->request(values: ['meta' => static fn ($value) => json_encode($value, JSON_THROW_ON_ERROR)])
    ->response(values: ['meta' => static fn ($value) => json_decode($value, flags: JSON_THROW_ON_ERROR)])
    ->post('create', '/items')
    ->build();
```

A full stage callback replaces these defaults as well. Callback arguments and
contexts have independent JSON containers: changing a nested object in one callback
does not change the original snapshot observed by the next callback.

For a nested route backed by an existing native model, use the same controller
contract and name the native parent attribute:

```php
Strangler::proxy('catalog')
    ->using(new ApiContract())
    ->within('CATALOG_ID')
    ->get('view', '/catalogs/{catalog_id}/items/{id}')
    ->put('edit', '/catalogs/{catalog_id}/items/{id}')
    ->build();
```

`within()` requires a single native primary key. It runs a scoped lookup only
when the outgoing path contains `{catalog_id}`. The package clones the finder and its criteria. An absent
record or a conflicting parent keeps the request in Yii before HTTP. Building the
filter does not query the model. Computed route parameters never become query or
body attributes.

For a different parameter source, use `request(parameters: ...)`. Its callback
receives the original action, query, and payload. Return route-only values, or
`false` to keep Yii. Conflicts with `fromBody` bindings also keep Yii.

Conventions translate every representable filter and ordering name; they do not
check backend capabilities. `filter[PERSON_ID]` becomes `filter[person_id]` and
`sort[SN]=DESC` becomes `sort=-sn`. The configured response identifier alias does
not rename input filters. Backend validation decides whether these names and values
are accepted. Automatic conventions do not impose a backend page-size maximum;
exact offset-to-page translation still requires a positive limit and aligned offset.
No post-HTTP native replay is attempted.

### Different semantics and response graphs

Declare relation differences on the same fluent object. The package traverses
only present, selected relations and reuses the compiled record graph:

```php
use Cosmira\Strangler\Strangler;

return Strangler::proxy('catalog')
    ->uppercase('CATALOG_ID')
    ->response(
        fields: ['name' => 'TITLE'],
        relations: [
            'items' => ['identifier' => 'ITEM_ID'],
        ],
    )
    ->get('view', '/catalogs/{id}')
    ->build();
```

A relation declaration supplies its native identifier. Its output name defaults
to the source name and its cardinality defaults to a collection. Set `name` or
`many: false` only for a difference. Ordinary child attributes follow the uppercase convention.
Unknown opaque JSON attributes are left intact; only declared relations are
traversed.

`response(except: [...])` omits upstream attributes. `optional: [...]` emits an
attribute only when the native projection selects it. `projection:` receives the
original response context and returns a canonical relation tree, or `null` to
retain all present declared relations. An empty tree omits all declared relations.
`attributes:` transforms the resource's root record before shared presentation
rules and naming. `after:` appends root-derived fields after related records are
mapped. Neither callback runs on a different resource. Common field names, value
formatters and omissions still apply to identifier-only child declarations;
controller-backed children use their own configuration.

For shared rules, put these differences in one controller contract. For an unusual
envelope, `response(using: ...)` still accepts a `Response\JsonResource`
configurator. It preserves previously declared naming and value differences.
`configureRecord()` extends its existing record schema; a full custom record
callback cannot be extended as a schema. `JsonResource::validateUsing()` adds a
final generated-response check. Failures propagate without replaying HTTP or Yii.

An explicit `mapQuery()`, `mapPayload()` or `mapJsonResponse()` replaces that stage
for its registered actions, regardless of whether `uppercase()` appears before or
after it. `uppercase('PERSON_ID', except: ['image'])` excludes only automatic
mappings. Register an explicit JSON handler for its JSON actions; a global
`mapJsonResponse()` also selects binary actions and would reject their bytes.
For example, `mapJsonResponse($handler, ['get', 'view', 'create', 'edit'])`
leaves an unconfigured `image` response untouched.
Existing advanced `Fields`, `Query`, `JsonResource` and `Record` mechanisms remain
available. Use `Record` only when an actual response graph needs different relation
names, child identifiers or projections; do not enumerate ordinary attributes.
Opaque nested JSON is not a model and its keys are left untouched.
Mapped attributes and present, selected relations must have distinct output names.
Conflicts, including a null value overwritten by a relation, are invalid upstream
responses; absent or omitted relations do not conflict with partial records.
`one()` and `many()` retain the source relation name unless `as` is supplied;
attribute naming remains independent. Lazy child factories run only for present,
selected relations containing data. A null to-one or an empty list does not build
a child schema; a populated list resolves its factory once for that list.

### Reuse related resource declarations

A controller-backed relation uses the referenced controller's record declaration,
including its required attributes, field aliases and native presentation callbacks:

```php
return Strangler::proxy('catalog')
    ->uppercase('CATALOG_ID')
    ->response(relations: [
        'items' => ItemController::class,
        'owner' => ['controller' => PersonController::class, 'name' => 'manager', 'many' => false],
    ])
    ->get('view', '/api/catalogs/{id}')
    ->build();
```

The referenced class must extend `CController`, accept the standard Yii controller
constructor, and declare exactly one Strangler filter with conventional record mapping. The
constructor receives the current controller module. The
package constructs it and reads `filters()` lazily. It does not call `init()` or
run an action, but the constructor and attached behaviors still execute. Keep
filter declarations free of queries and other side effects. Missing or ambiguous
record declarations are configuration errors.

Only present, selected relations resolve a controller. Its routes, query defaults,
root projection, response envelope and operation results are not executed for a
nested record. The parent supplies that record's projection. Recursive relations
follow the finite response data; opaque JSON attributes are not traversed.

A relationship can carry fields that do not belong to the standalone resource.
Use `fields`, `except` or `after` on that edge to describe the difference. For example,
`'statuses' => ['controller' => StatusController::class, 'except' => ['usage_count']]`
replaces that role's omission list without changing `StatusController`'s schema.
These overrides produce a separate immutable schema; they do not alter other uses.

### Keep query and response selection together

Use `query(defaults: ...)` when native actions have relation defaults that differ from an
explicit `with` tree:

```php
Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->query(
        defaults: ['owner' => []],
        replaceFor: ['edit'],
        emptyFor: ['delete'],
        extendFor: ['get' => ['labels']],
    )
    ->response(relations: [
        'owner' => ['identifier' => 'OWNER_ID', 'many' => false],
        'labels' => ['identifier' => 'LABEL_ID'],
    ])
    ->get('get', '/items')
    ->put('edit', '/items/{id}')
    ->delete('delete', '/items/{id}')
    ->build();
```

`defaults` accepts a canonical include tree or a callback receiving the original
action and query context. The callback returns a tree, or `null`; it can call an
existing model's default projection. Use `defaults: []` when only action overrides
are needed. With no `defaults`, ordinary query translation and the existing
response projection remain unchanged. Selection rules require explicit defaults.
The package knows no application-specific action names.

- `replaceFor` uses native defaults even when `with` is present.
- `emptyFor` selects no relations.
- `extendFor` combines the listed defaults with `with`; explicit `with=[]` stays empty.
- Other actions use a nonempty `with`, otherwise native defaults. `merge_with` is
  appended to those defaults only for `mergeFor` actions, which default to `['view']`.

Malformed `with` or `merge_with` keeps the request in Yii before HTTP. A `null`
selection also keeps it in Yii. The upstream query contains the selected top-level
relation names. Nested branches remain in the response projection; this setting
does not turn them into dotted upstream includes. No result is cached between requests.

### Preserve native relation writes

A controller contract can return native writable relation names from
`nativeRelations($controller)`. On `create` and `edit`, requests containing these
fields stay in Yii before HTTP. Names are case-insensitive; their values may be
arrays, JSON strings or null. Ordinary undeclared fields still pass through.

Declare only relations whose writes your adapter preserves upstream:

```php
Strangler::proxy('catalog')
    ->using(new ApiContract())
    ->request(relations: ['contacts'], actions: ['create'])
    ->post('create', '/api/items')
    ->put('edit', '/api/items/{id}')
    ->build();
```

This declaration permits the existing payload adapter to handle `contacts` on
creation; it does not convert nested values or add upstream write support.
Relations fully consumed by `fromBody` route bindings need no declaration.
The whole request stays in Yii if any unadapted native relation remains.

### Declare request guards and native checks

Structural differences can keep a request in Yii without a procedural adapter:

```php
Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->request(nativeOn: ['ATTACHMENTS'], actions: ['edit'])
    ->request(
        required: ['ITEM_ID' => 'identifier', 'PARENT_ID' => 'identifier'],
        methods: ['PUT', 'POST'],
        actions: ['move'],
    )
    ->request(before: [$controller, 'checkParentAccess'], actions: ['move'])
    ->put('move', '/items/{id}/parents')
    ->build();
```

`nativeOn` matches payload field names case-insensitively. `required` accepts
`identifier` (a nonblank scalar, including numeric zero) and `rows` (an array whose
values are arrays). A missing body identifier can use the original query's `id`;
conflicting identities retain Yii. `methods` checks the incoming method, while
the route method defines the outgoing request. `actions=[]` applies a registration
to every mapped action. Repeated guard registrations compose. Action names are
case-insensitive, like route declarations; configured method names are normalized
to uppercase.

After structural guards pass, `before` receives the original payload, normalized
action name and native controller. Use it for existing authorization checks, not
payload conversion. Checks run in registration order before mapping and HTTP;
changing their array argument does not change the forwarded snapshot. Exceptions
propagate through the native Yii error handling. Readers receive the native
controller and normalized action; keep them for parsing an input envelope or file.

`response(required: ['id' => 'string', 'enabled' => 'bool'])` checks upstream
attributes before value conversion. Types are `string`, `int`, `bool` and `array`;
missing, null or wrong-type values produce `502 invalid_upstream_response`.

Envelope checks use the existing `response(using: ...)` configurator:

```php
use Cosmira\Strangler\Response\JsonResource;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->response(using: static fn (JsonResource $resource) => $resource
        ->requireData()
        ->rejectEmpty(['meta', 'added_ids'], 'already_exists', 400, ['attach'])
    )
    ->route('attach', 'PATCH', '/items/{id}/labels')
    ->build();
```

`response(empty: ['delete'], rejectEmpty: ['copy' => [['meta', 'added_ids'], 'exists_entity', 400]])`
configures these outcomes without a callback. `response(projection: '*')` includes
all declared relations regardless of the native selection.

`requireData()` requires a successful JSON envelope to have a `data` property,
including operations with an empty mapped response. HTTP 204 remains valid.
`rejectEmpty()` matches an explicit empty array at the literal path for the selected
actions. A missing path or another value does not match. Neither rule replays a write.

## Share a controller contract

A `ControllerContract` connects existing application methods to the package.
The [README example](../README.md#connect-existing-rest-controllers) implements
its required `model()` and `payload()` methods. An optional `configure(Strangler $proxy)`
method sets shared fluent defaults; later controller declarations override them.
Register its class once as `params.strangler.controller_contract`. Each
`Strangler::proxy()` then applies those defaults without a per-controller `using()`.
Building filters constructs the contract and runs `configure()`, but does not read
model metadata or request data. Keep `configure()` declarative.
`using(new ApiContract())` remains available for an explicit contract override;
the supplied instance is used and its configuration is applied after the application
defaults. Omit `using()` when the application contract is sufficient.
An explicit `request(reader: ...)` overrides the contract's payload reader.

The configured contract enables conventions and reads the primary key from the
controller's native model when handling a selected request. Building filters does not read the
model. Disabled, unmapped, and early-bypassed requests do not resolve it.

By default the backend primary key is `id`. To use database field names, include
`strtolower($identifier) => $identifier` in the contract’s `responseFields()` map.
The declared primary-key mapping also applies to related records without database queries.

Each related controller supplies its own model and primary key when its response
schema is needed. Mark a binary GET route with
`get('image', '/people/{person_id}/photo', binary: true)`. It opts out of automatic
success JSON mapping for that action. Request, query,
field aliases and error handling still apply. This preserves binary bytes without treating malformed JSON
on ordinary routes as a binary response. Existing exclusions through `using()`
and `uppercase()` remain supported.

Automatic binding requires one nonempty string primary key. For a different API
identifier or a composite-key model, declare the API identifier explicitly with
`uppercase('ITEM_ID')`. Explicit stage mappings retain their precedence.

Override only the shared differences your application needs:

| Hook | Result |
| --- | --- |
| `identifier()` | Optional explicit native identifier. The default `null` resolves the key from the current model. |
| `relations()` | Shared relation declarations, including controller-backed schemas and native name differences. |
| `nativeRelations($controller)` | Native nested-write fields retained in Yii unless explicitly adapted or consumed by route bindings. |
| `responseFields($identifier)` | Upstream-to-native field exceptions for that root or related resource. |
| `responseAttributes($attributes, $identifier, $relation)` | Attribute values before naming and relation traversal. |
| `responseAfter($mapped, $raw, $projection, $identifier, $relation)` | Derived attributes after related records are mapped. |
| `responseProjection($context)` | A canonical selection tree, or `null` to retain present relations. |
| `responseResult($json, $response, $context)` | An operation-specific response, or `null` to continue ordinary record mapping. |
| `validationErrors($controller, $action, $payload)` | Native error envelope for a failed create or edit, or `null` for standard formatting. |
| `errorCodes()` | HTTP status-to-native error codes. Defaults include `403 => 'forbidden'`. |
| `validateResponse($response)` | A final application response check before output. |

The package owns recursive traversal, immutable schema reuse, error normalization,
and the forwarding attempt. Contract hooks contain application differences. They
must not save models or replay a write. Native validation receives the original
payload snapshot, so it does not need to parse the request again.

Response conventions apply to root and related records. Use the identifier and
relation arguments when a value has different representations in those positions.
Explicit filter-level relation declarations override shared declarations with the
same upstream name. A filter-level `projection:` replaces the contract projection;
attribute callbacks run on their root record before the shared attribute hook;
derived-field callbacks run on their root record after shared finalizers. Shared
hooks are for application-wide conventions, not resource dispatch by identifier.

`Format::timestamp()` in `Cosmira\Strangler\Schema` formats full ISO 8601
source timestamps with seconds and a timezone, with optional fractional seconds.
It rejects invalid dates and non-string values with `Exception\InvalidUpstreamResponse`.
In a JSON response mapper, the pipeline turns that structural failure into a 502 response:

```php
use Cosmira\Strangler\Schema\Format;

Format::timestamp('2026-11-08T12:34:56.123456Z', 'Y-m-d H:i:s.u');
// 2026-11-08 12:34:56.123456
```

A date-only string is not a supported source timestamp. The output format uses
PHP's `DateTimeImmutable::format()` tokens; formatting preserves the source's
timezone unless your application explicitly changes it.

## Advanced: adapt a different envelope

Use `response(using:)` to change the success envelope while keeping conventional
record mapping:

```php
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->response(using: static fn (JsonResource $resource) => $resource
        ->envelope(['success' => true])
    )
    ->get('view', '/items/{id}')
    ->build();
```

`{"data":{"id":42}}` becomes `{"success":true,"data":{"ITEM_ID":42}}`.

For a different data location or write structure, use explicit stage callbacks.
The [catalog mappings](../examples/catalog/proxy.php) provide a runnable example
with nested writes, item and list responses, and validation errors.

## Advanced: replace a mapping stage

All field maps use `SOURCE => TARGET`. A flat `mapPayload(['NAME' => 'title'])`
produces an object containing only the mapped fields. An empty result is `{}`.
A callback can explicitly return `[]` for an array. Use a short PHP callback when
nesting, combining fields, or converting a value requires application knowledge.

Keys such as `status.IDENTITY_STATUS_ID` are literal keys. They are not paths.
Only helper `from`, `to`, `totalFrom`, and `totalTo` arguments accept paths, expressed
as arrays of segments. Missing fields stay absent, existing `null` stays `null`, and
JSON `{}` and `[]` remain distinct. Big integers decode as strings without rounding.

`Mapping::legacyQuery()` translates allowed filters, sort directions, includes, and
exact offset pagination. Unsupported fields or an offset not divisible by the limit
return `false`, keeping Yii before any upstream request. No records are skipped by
rounding. In a full replacement, explicit maps can restrict filters, sorts and
includes; the default convention resolves ordinary names automatically.

## Preserve errors and headers

`uppercase()` does not rename error fields or change error envelopes. Without an
error mapper, backend errors keep their original status and body. For example,
`{"errors":{"display_name":["Required"]}}` returned with HTTP 422 stays unchanged.

Use `mapJsonErrors()` when the existing client requires a different error contract.
It receives backend errors and synthetic package errors. Validation
messages keep the old form names through `Mapping::validationErrors()`. Unknown
fields collect under `_general`; aliases merge their messages. The application
continues to choose business error codes and any status translation.

Malformed backend JSON and helper-detected invalid response structure produce one
502 `invalid_upstream_response`, then run the error mapper. A programming exception
in a callback reaches the host error handler unchanged. Neither path retries a write
or runs the old Yii action after forwarding. Configure the JSON error action from
[the integration guide](integration.md#api-errors-from-yii) for application bugs.

`Json::response()` serializes the body and retains `X-Request-Id`, `Retry-After`, and
`X-RateLimit-Remaining`. It removes stale compression, length, validators, and digest
metadata. Set extra headers deliberately with the standard PSR response methods.

If no response mapper is selected, the original response passes through. Query-only
or response-only mappings preserve raw request JSON bytes when no payload mapper is
selected. `request(reader: ...)` retains its existing custom-reader behavior.

## Keep routing and security explicit

The first argument of `Strangler::proxy()` is a feature key. In
`put('update', '/api/items/{id}')`, the action name belongs to Yii and the path and
HTTP method belong to the backend. Keep incoming method and CSRF rules in preceding
Yii filters. A route must start with one `/`; it cannot change the upstream origin.

`uppercase('ID')` captures the original body identifier before mapping. The request
`id` takes precedence. Without conventions, resolve body-only identifiers explicitly:

```php
->request(parameters: static fn (array $context): array => [
    'id' => $context['query']['id'] ?? $context['payload']['ID'] ?? null,
])
```

Reject conflicting identifiers in preceding validation before resource-access checks.
Unresolved placeholders return 400 without contacting the backend. Use
`route('image', 'HEAD', '/items/{id}/image')` or
`route('move', 'PATCH', '/items/{id}/groups')` for those outgoing methods.

For legacy form fields, read the same validated input as the preceding filter:

```php
->request(reader: static fn (CController $controller): array => $controller->validatedInput)
```

This turns validated form values into upstream JSON. It does not forward files.
The [runnable catalog](../examples/catalog/README.md) includes JSON and form variants,
ACL, CSRF, tenant isolation, idempotency, and a real HTTP contract verifier.

## Enable the feature after checking compatibility

Keep the feature off until the client's requests and responses match through both
executors. Verify list, item, writes, errors, permissions, relations, and timeouts.
Unmapped actions and deliberate bypasses stay in Yii before forwarding.

A payload predicate receives the original payload snapshot, lowercase action and
original query. For example, a present field, including null, can select Yii:

```php
->request(bypass: static fn (array $payload, string $action): bool =>
    $action === 'update' && array_key_exists('LEGACY_ONLY', $payload))
```

The complete example shows a nested-value predicate. An early `bypassUsing()` match
does not read the body. The payload predicate runs after one read and before mapping.
Exceptions reach the application error handler; they do not select the old handler.

Roll back by disabling the feature for subsequent requests. Neither disabling a flag
nor returning `false` from a request mapper replays an operation already sent upstream.

## Advanced: explicit query and resource contracts

Use this lower-level API only when the contract needs a full replacement or an
explicit capability restriction. Ordinary uppercase CRUD uses the single builder
above and needs none of these field lists.

`Query` and `JsonResource` are callable value builders accepted by `mapQuery()` and
`mapJsonResponse()`. Each fluent method returns a new configuration, leaving its
source reusable. Application rules belong in a `recordUsing()` callback.

```php
use Cosmira\Strangler\Query;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Strangler;

$resource = JsonResource::make()->fields(['id' => 'key', 'title' => 'name']);

return Strangler::proxy('catalog')
    ->mapQuery(Query::legacy()
        ->filters(['name' => 'title'])
        ->sorts(['name' => 'title'])
        ->includes(['owner' => 'author']))
    ->mapPayload(['name' => 'title'], ['create', 'edit'])
    ->mapJsonResponse($resource)
    ->get('get', '/api/items')
    ->get('view', '/api/items/{id}')
    ->post('create', '/api/items')
    ->put('edit', '/api/items/{id}')
    ->delete('delete', '/api/items/{id}')
    ->build();
```

### Nested values and change snapshots

The pure helpers in `Cosmira\Strangler\Schema\Mapping` cover structural differences
without adding another application mapper class:

| Helper | Behavior |
| --- | --- |
| `pluck($records, $fields)` | Select the first non-null key in preference order from each record, retaining scalar input for validation. |
| `requestRecords($records, $fields, $defaults = [], $required = [], $values = [])` | Rename each nested record's own keys, add defaults under translated names, and optionally select records containing non-null required fields. |
| `changes($changes, $sides, $fields, $except = [], $values = [])` | Convert field-oriented snapshots into named sides, retaining an unrecognized business payload unchanged. |

For a `Fields::uppercase()` schema, `requestRecords()` translates ordinary input
keys without listing them. It preserves nested opaque JSON values; it does not
recursively rename those values or make malformed scalar rows valid.

`recordsWith()` returns the same mapping as a callback. Use `values` for explicit
conversions after renaming, such as metadata stored as a JSON string:

```php
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Schema\Mapping;

$contacts = Mapping::recordsWith(Fields::uppercase(), values: ['meta' => Format::jsonString(...)]);
```

Formatter keys use mapped field names; configured defaults pass through the same formatters.

`changes()` receives source-to-target side names, such as
`['from' => 'old', 'to' => 'new']`. A change
`['name' => ['from' => 'Before', 'to' => 'After']]` with `Fields::uppercase()` becomes
`['old' => (object) ['NAME' => 'Before'], 'new' => (object) ['NAME' => 'After']]`.
Null snapshot values are omitted. An incomplete or unrecognized change structure
passes through instead of being interpreted as a change set.

### Explicit field selections

`Fields::uppercase()` converts every field in an upstream record to uppercase.
Declare naming exceptions with `rename()` instead of listing ordinary fields.

```php
use Cosmira\Strangler\Schema\Fields;

$fields = Fields::uppercase()->rename([
    'id' => 'PERSON_ID',
    'name' => 'TITLE',
]);

$resource = JsonResource::make()->fields($fields);

$query = Query::legacy()
    ->filters($fields->filterAs('display_name', 'query')->only(['display_name']))
    ->sorts($fields->only(['name']));
```

`display_name` becomes `DISPLAY_NAME` without a declaration. Newly added upstream
fields follow the same convention. This transforms names, not visibility: all
fields in the upstream public resource remain in the response.

`only()` selects explicit fields for filters and sorting; naming a field does not
enable it as a filter. Write translation is independent of the response resource:
use `mapPayload(['DISPLAY_NAME' => 'display_name', 'NOTE' => 'note'])` for an
explicit input contract. Unknown keys are ignored and existing null values remain.

The schema describes **upstream name to legacy name**. `Query` reverses selected
names for filters, sorting and includes. `Fields::filterAs()` and `Fields::sortAs()`
change their respective query direction without changing response names. In this
example, `filter[DISPLAY_NAME]` becomes `filter[query]`, while the response uses
`DISPLAY_NAME`. `Fields::writes()` returns an inverse field map when the same names
are intentionally shared with an explicit input mapper.

All schema methods return a new value. `only()` selects a fixed set of fields;
`Fields::uppercase(['id', 'display_name'])` is the explicit-selection shorthand.
Unknown names in an explicit selection are rejected. Duplicate output names are
rejected rather than silently overwriting values.

Renaming applies to one record at a time. Nested resource records use their own
schema; arrays stored as field values are preserved. Date formatting, boolean
values, null omission and changes to record shape remain application rules.

`Query::legacy()` defaults to list action `get`, ignored query keys `id` and `_`,
a default page size of 20, and a maximum page size of 100.
`filters()`, `sorts()`, and `includes()` use the source-to-target names understood
by `Mapping::legacyQuery()`. `ignore()` and `lists()` replace their defaults. Non-list actions discard `start` and `limit`
before validation and omit generated pagination; unsupported projections, fields,
filters, sorts, and includes still return `false` to retain legacy execution.

### Response defaults

`JsonResource::make()` defaults to the common Yii REST convention: envelope
`['state' => 'data']`, records under `data`, collection action `get`, total from
`['meta', 'total']` to `['meta', 'totalCount']`, empty action `delete`, and HTTP 200.
An upstream 204 or an action selected by `empty()` produces only the configured
envelope, without reading a record. The inline response mapper still requires valid
JSON or an empty body: malformed JSON produces a controlled 502 before `JsonResource`
is called, including for empty actions and upstream 204 responses.
These are compatibility defaults, not Yii
framework requirements. `empty([])` disables action-based empty responses.

- `fields(array|Fields)` selects and renames record fields; without it records pass through.
- `recordUsing(callable)` replaces record transformation; the callback receives the
  validated record and original request context. Date, null, and domain conversions
  belong here; nested records can use `Mapping::fields()`.
- `envelope(array)`, `totals(array $sourcePath, array $targetPath)`,
  `collections(list<string>)`, and `empty(list<string>)` replace those defaults.
  Paths are literal segment lists. Collection totals must exist and be nonnegative integers.
- `status(?int)` sets the mapped status; `null` preserves the upstream status for
  nonempty responses. Empty responses with `status(null)` use HTTP 200.

Missing or malformed upstream data is rejected by `Mapping` with
`InvalidUpstreamResponse`, so the existing inline mapping handler produces its
controlled 502 response. A record callback must throw `InvalidUpstreamResponse`
when it rejects upstream data. Other callback exceptions, including `TypeError`
and `UnexpectedValueException`, propagate as application programming errors.
Lower-level `Mapping` methods remain available for other response shapes.

## File uploads

Use `mapFiles()` to send a multipart request for selected actions:

```php
Strangler::proxy('directory')
    ->mapFiles(['files[0]' => 'photo'], ['uploadImage'])
    ->post('uploadImage', '/api/people/{person_id}/photo')
    ->build();
```

Keys are Yii upload names, including bracket paths; values are upstream field names.
Files are passed as read-only streams from PHP's temporary upload directory.
Use the default Guzzle cURL handler to stream large multipart bodies; it may buffer
small bodies. Without
cURL, Guzzle's PHP stream handler materializes that body as a string. Other files
are not forwarded.
`mapPayload()` can rename scalar form fields. Without a payload reader, fields come from `$_POST`.
Nested form values are not supported. Actions without file mappings keep their JSON behavior.

Missing or failed uploads are omitted so the backend can validate required files.
The backend must validate file size, content and permissions. Multipart requests use the same
trusted headers, timeouts and single-attempt behavior as JSON requests.

See [file performance](performance.md) for reproducible transfer and memory measurements.

### Complete native collection reads

A native list request without `limit`, or with `limit=-1`, can mean the whole
collection. Declare this difference for the relevant GET action:

```php
Strangler::proxy('directory')
    ->uppercase('ITEM_ID')
    ->query(complete: ['get' => 100])
    ->get('get', '/api/items')
    ->build();
```

The package requests successive Laravel paginator pages on the same trusted path,
retaining mapped filters, sort and includes. It combines their `data` before the
normal response mapping and preserves `meta.total` for native `totalCount`.
A nonnegative native `start` skips that many records, including offsets inside an
upstream page; `start=-1` means zero. A positive explicit limit keeps the existing
single-page translation and unsupported queries still run natively before any
upstream attempt. Only declared actions resolving to GET, called with GET, collect
pages. Writes are never replayed.

The upstream paginator must expose integer `meta.current_page`, `last_page`,
`per_page` and `total`. Each page must match its requested number and the initial
collection total. A collection without `meta` is accepted as a complete list.
Pagination links are never followed. A later error discards earlier pages and
uses the normal error response mapping; malformed or inconsistent pagination
returns `502 invalid_upstream_response`. There is no fallback after HTTP begins.
Each actual attempt runs `afterAttempt`; the configured transport timeout is one
deadline for the entire read, not a renewed timeout per page.

Page sizes must be positive and accepted by the upstream paginator. Each size
bounds one requested page, not the total collection.
Mapped JSON and the combined collection use memory proportional to the complete
result, like a native unlimited response; there is no silent record or byte cap.
Unmapped binary/file responses keep their existing streamed output. Offset pages
do not provide a database snapshot: use stable server ordering; concurrent edits
can still replace records without changing the total.

Applications that already define a `ControllerContract` can declare unlimited
collection defaults once with the protected `collectionPages(CController $controller): array`
hook (6.1.0). Return action names and transport page sizes, for example
`['get' => 100]`, only for native actions whose default is unlimited. Return `[]`
for paginated native defaults. Controller filters then need only their routes;
explicit positive request limits still select a single page. An explicit local
`query(complete: ...)` page size takes precedence over the shared declaration.
