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

Without mappings, Strangler forwards the request and returns the backend response
without translating its JSON contract. Use that mode when the two APIs already match.

Use `uppercase()` when the client expects uppercase attributes and the success
envelope shown below. Describe only directional exceptions:

```php
use Cosmira\Strangler\Strangler;

return Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->response(fields: ['updated_at' => 'CHANGE_DATE'])
    ->get('view', '/items/{id}')
    ->put('edit', '/items/{id}')
    ->build();
```

Ordinary request fields, filters and ordering names are lowercase upstream;
ordinary response attributes are uppercase in Yii. `with` becomes `include`, and
`start/limit` becomes `page/per_page`. There are no required field, writable, filter
or sort lists. Keep native request/access checks before Strangler and validate the
backend independently: naming conventions do not grant permissions.

For a backend item `{"data":{"id":42,"updated_at":"2026-11-08"}}`, this returns
`{"state":"data","data":{"ITEM_ID":42,"CHANGE_DATE":"2026-11-08"}}`.
The convention reads records under `data`; list responses also require `meta.total`.
It uses HTTP 200 for mapped success responses. See [response defaults](#response-defaults)
for empty actions and custom envelopes. This convention maps successful responses;
[error contracts](#preserve-errors-and-headers) require separate configuration.

`response(fields: [...])` maps upstream to legacy names. `request(fields: [...])` maps legacy to
upstream names. The two directions are deliberately independent: a Person may
return `given_name` while accepting `givenname`.

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
        filters: ['display_name' => 'query', 'is_visible' => 'visible'],
        sorts: ['change_date' => 'updated_at'],
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

`query(values: [...])` formats declared filter values after ordinary names and list
values have been normalized. Formatter keys use upstream filter names. For example,
expand a historic name while retaining both spellings for the backend:

```php
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->query(values: [
        'kind' => static fn ($value) => Format::expand($value, ['legacy' => ['modern']]),
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
    ->uppercase('ITEM_ID')
    ->within('CATALOG_ID')
    ->get('view', '/catalogs/{catalog_id}/items/{id}')
    ->put('edit', '/catalogs/{catalog_id}/items/{id}')
    ->build();
```

`within()` runs a scoped native lookup only when the selected outgoing path
contains `{catalog_id}`. The package clones the finder and its criteria. An absent
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
        fields: ['updated_at' => 'CHANGE_DATE'],
        relations: [
            'items' => ['identifier' => 'ITEM_ID', 'name' => 'items', 'many' => true],
        ],
    )
    ->get('view', '/catalogs/{id}')
    ->build();
```

A relation declaration contains its upstream name, native identifier, output name,
and cardinality. Ordinary child attributes follow the same uppercase convention.
Unknown opaque JSON attributes are left intact; only declared relations are
traversed.

`response(except: [...])` omits upstream attributes. `optional: [...]` emits an
attribute only when the native projection selects it. `projection:` receives the
original response context and returns a canonical relation tree, or `null` to
retain all present declared relations. An empty tree omits all declared relations.
`attributes:` transforms values before naming. `after:` appends derived fields
after related records are mapped. Both receive the resource identifier and the
source relation name; the root relation name is `null`.

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

## Share a controller contract

A `ControllerContract` connects existing application methods to the package.
The [README example](../README.md#connect-existing-rest-controllers) implements
its required `model()` and `payload()` methods. Register it with
`using(new ApiContract())`; keep all route declarations on the same `Strangler`.
An explicit `request(reader: ...)` overrides the contract's payload reader.

If the shared contract already stores its native identifier, override
`identifier(): ?string` to return it and call `uppercase()` without an argument.
The default hook returns `null`. An explicit `uppercase('ITEM_ID')` takes
precedence. Both fluent call orders resolve the identifier when `build()` runs;
neither reads model metadata or queries the database. If neither source provides
an identifier, `build()` rejects the configuration.

Override only the shared differences your application needs:

| Hook | Result |
| --- | --- |
| `identifier()` | Optional native identifier used by `uppercase()` without an argument. |
| `relations()` | Upstream relation names mapped to `identifier`, output `name`, and `many` cardinality. |
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
attribute and derived-field callbacks run after their shared contract hooks.

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

Use full stage callbacks when the client envelope or write structure differs from
the ordinary convention. This example translates `start/limit` to `page/per_page`,
nests write fields, and restores a different item, list, and error envelope.
Place the resulting filter after the existing access, method, CSRF, and input checks.

The old write body is `{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}`.
The backend accepts `{"product":{"title":"Desk","enabled":true},"category":{"id":7}}`.
It returns `{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}`.
Lists use `{"data":[...],"meta":{"total":50}}`.

```php
use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Response\Json;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use Psr\Http\Message\ResponseInterface;

$fields = ['id' => 'ID', 'title' => 'NAME'];
$errorFields = [
    'title' => 'NAME',
    'product.title' => 'NAME',
    'category.id' => 'CATEGORY_ID',
    'product.enabled' => 'ACTIVE',
];
$relations = static function (array $query): array {
    $with = $query['with'] ?? [];
    if (!is_array($with) || !array_is_list($with)
        || array_filter($with, static fn ($name): bool => $name !== 'category')) {
        throw new InvalidArgumentException('Only with[]=category is supported.');
    }

    return $with;
};
$record = static function (mixed $item, array $request) use ($fields, $relations): object {
    if (!$item instanceof stdClass || !isset($item->id, $item->title, $item->enabled, $item->category->id)) {
        throw new InvalidUpstreamResponse('The catalog API returned an incomplete item.');
    }
    $legacy = Mapping::fields($item, $fields);
    $legacy->ACTIVE = $item->enabled ? 1 : 0;
    $legacy->CATEGORY_ID = $item->category->id;
    if (in_array('category', $relations($request['query']), true)) {
        $legacy->CATEGORY = Mapping::fields($item->category, ['id' => 'ID']);
    }

    return $legacy;
};

return Strangler::proxy('catalog')
    ->request(parameters: static function (array $context): array {
        $id = $context['query']['id'] ?? $context['payload']['ID'] ?? null;

        return $id === null ? [] : ['id' => $id];
    })
    ->mapQuery(static fn (): array => [])
    ->mapQuery(static function (array $query) use ($relations): array|false {
        $relations($query);
        unset($query['with']);

        return Mapping::legacyQuery($query);
    }, ['get'])
    ->mapPayload(static fn (array $payload): array => [
        'product' => ['title' => $payload['NAME'], 'enabled' => (bool) $payload['ACTIVE']],
        'category' => ['id' => $payload['CATEGORY_ID']],
    ], ['create', 'update'])
    ->mapJsonResponse(static function (mixed $json, ResponseInterface $response, array $request) use ($record): ResponseInterface {
        if ($response->getStatusCode() === 204) {
            return $response;
        }
        $map = static fn (mixed $item): object => $record($item, $request);
        $body = $request['action'] === 'get'
            ? Mapping::collection($json, $map, ['success' => true], to: ['rows'],
                totalFrom: ['meta', 'total'], totalTo: ['total'])
            : Mapping::item($json, $map, ['success' => true]);

        return Json::response($response, $body);
    })
    ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, [
        'success' => false,
        'error' => ['code' => $json->code ?? $json->error ?? 'request_failed',
            'fields' => (array) Mapping::validationErrors($json->errors ?? new stdClass(), $errorFields)],
    ]))
    ->request(bypass: static fn (array $payload, string $action): bool =>
        $action === 'update' && ($payload['OPTIONS']['MODE'] ?? null) === 'legacy')
    ->get('get', '/api/items')
    ->get('view', '/api/items/{id}')
    ->post('create', '/api/items')
    ->put('update', '/api/items/{id}')
    ->build();
```

Without `with`, the item response stays
`{"success":true,"data":{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}}`.
`with[]=category` also adds `CATEGORY: {"ID":7}` while preserving `CATEGORY_ID`.
The callback uses the request snapshot directly. It does not rely on a previous
request mapper or read global Yii state. Constructing this configuration does not
validate request data before the security filters run.

For a list, the legacy client gets `{"success":true,"rows":[...],"total":50}`.
A 204 response stays empty in this example. To restore a legacy success envelope
instead, return `Json::response($response, ['state' => 'data'], 200)`.

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
| `pluck($records, $fields)` | Select the first present key in preference order from each record, retaining scalar input for validation. |
| `requestRecords($records, $fields, $defaults = [], $required = [])` | Rename each nested record's own keys, add defaults under translated names, and optionally select records containing non-null required fields. |
| `changes($changes, $sides, $fields, $except = [], $values = [])` | Convert field-oriented snapshots into named sides, retaining an unrecognized business payload unchanged. |

For a `Fields::uppercase()` schema, `requestRecords()` translates ordinary input
keys without listing them. It preserves nested opaque JSON values; it does not
recursively rename those values or make malformed scalar rows valid.

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
    'updated_at' => 'CHANGE_DATE',
]);

$resource = JsonResource::make()->fields($fields);

$query = Query::legacy()
    ->filters($fields->filterAs('display_name', 'query')->only(['display_name']))
    ->sorts($fields->only(['updated_at']));
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
