# Integrating Strangler safely

Strangler routes requests; your application owns access rules, the API contract and
the deployment procedure. Complete these steps for each feature before enabling it.

- [Access checks and Yii hooks](#access-checks-and-yii-hooks)
- [Write-action validation](#write-action-validation)
- [API errors from Yii](#api-errors-from-yii)
- [Trusted channel](#trusted-channel)
- [Configuration reference](#configuration-reference)
- [Inline mappings](#inline-mappings)
- [Independent read and write flags](#independent-read-and-write-flags)
- [Headers, TLS and payloads](#headers-tls-and-payloads)
- [Rollout and rollback](#rollout-and-rollback)
- [Logging and callbacks](#logging-and-callbacks)
- [Before deployment](#before-deployment)
- [Local development](#local-development)

## Access checks and Yii hooks

Put authentication, HTTP verb checks, CSRF validation for session-authenticated
writes, input validation and resource permissions **before Strangler**, in filters
that complete their checks before calling `$filterChain->run()`.
Use your actual application's filters; names in the README are examples.

Forwarding skips the legacy action, `beforeAction()` and `afterAction()`, including
inherited controller implementations. Ending the proxied response also prevents
previous filters from returning to `postFilter()`. Audit transaction cleanup and
logging in those hooks. If cleanup is necessary, use a tested request-end mechanism
such as Yii's `onEndRequest`; it is not a place to defer an access decision.

Strangler does not infer resource permissions from `X-User-Id`. Decide which checks
remain in Yii and which business invariants the new backend must enforce. A public
Yii action may forward a guest request; its user identifier is an empty string.

The complete [catalog example](../examples/catalog/README.md) implements these
checks in a real host application. Use it to verify the order, not just copy names.

## Write-action validation

Validate the incoming method and input before resource-access checks and Strangler.
Use the same parsed input as `request(reader:)`. Reject malformed bodies and
conflicting query/body identifiers before checking access to that identifier.
Keep session-authenticated write CSRF checks in the preceding filters.

The [catalog controller](../examples/catalog/controllers.php) demonstrates these
checks, including a native POST that becomes an upstream PUT. Its errors use the
host's API error handler.

Do not hold an open application transaction across the backend call.

## API errors from Yii

For API controllers, configure an application-owned error action so mapping bugs and
invalid backend responses keep the legacy JSON error contract. In Yii configuration:

```php
'components' => [
    'errorHandler' => ['errorAction' => 'apiError/error'],
],
```

Use your application's JSON error action. It must remain reachable without
repeating the failed filters. The [catalog error action](../examples/catalog/controllers.php)
shows a complete implementation. Preserve a separate HTML error handler for browser
pages, and run production with `YII_DEBUG=false`.

Malformed mapped JSON produces `502 invalid_upstream_response`; other callback
exceptions reach Yii's error handler. Without a JSON mapper, response bytes pass
through. Neither path retries the backend or runs the native action after forwarding.

## Trusted channel

The backend must accept identity headers only from your Yii application. A private
network or authenticated gateway can provide this trust. If you choose a shared
service token, configure it on the server, never from the incoming client's header.
Use HTTPS and a secret supplied through your deployment's environment on both sides.

In Yii's configuration bootstrap:

```php
$token = getenv('STRANGLER_TOKEN');
if (!is_string($token) || $token === '') {
    throw new RuntimeException('STRANGLER_TOKEN must be configured.');
}

$catalogEnabled = filter_var(
    getenv('STRANGLER_CATALOG_ENABLED') ?: 'false',
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
) ?? false;

// Add this under Yii's params.
$strangler = [
    'base_uri' => 'https://backend.example',
    'headers' => ['X-Strangler-Token' => $token],
    'forward_headers' => ['Accept-Language', 'X-Request-Id', 'Idempotency-Key'],
    'features' => ['catalog' => $catalogEnabled],
    'timeout' => 10,
    'connect_timeout' => 3,
];
```

Assign `$strangler` to `params.strangler`. Only boolean `true` enables a feature;
strings such as `'false'` do not. Parse environment values explicitly as above.

At the new backend's entry point, before routing or using `X-User-Id`:

```php
$expected = getenv('STRANGLER_TOKEN');
$provided = $_SERVER['HTTP_X_STRANGLER_TOKEN'] ?? '';

if (!is_string($expected) || $expected === '') {
    http_response_code(503);
    exit;
}

if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit;
}

$userId = $_SERVER['HTTP_X_USER_ID'] ?? ''; // Empty for a permitted guest request.
```

Verify that a direct call without the token fails and a permitted Yii request works.
A client's `X-Strangler-Token` is discarded; only the server configuration supplies
it. The diagnostic `X-Strangler: 1` must never authorize a request. Rotate the token
through your deployment process; keep it out of repositories and request logs.

## Configuration reference

All settings below belong to `params.strangler`; feature names match
`Strangler::proxy('catalog')`. Keep environment parsing in your application's
configuration bootstrap.

| Setting | Default | Contract |
| --- | --- | --- |
| `base_uri` | Missing: handled request returns `500` | One HTTP(S) origin, e.g. `https://backend.example:8443`. No credentials, path prefix, query or fragment. Put `/v2` in route paths. |
| `features` | `[]` | Map of feature names to boolean flags; only `true` forwards. |
| `headers` | `[]` | Server-owned string headers, including the trusted token. Override forwarded client values; no invalid names or newlines. |
| `forward_headers` | `['Accept-Language', 'X-Request-Id', 'Idempotency-Key']` | Explicit client-header allowlist. Reserved identity/token and transport headers remain blocked. |
| `timeout` | `10` | Positive finite seconds for the full request. Invalid values use the default. |
| `connect_timeout` | `3` | Positive finite seconds for connecting. Invalid values use the default. |
| `verify_ssl` | `true` | Boolean or absolute CA-bundle path. Use `false` only deliberately for local development. |
| `log_requests` | `false` | Enable forwarding logs; `YII_DEBUG` also enables them. |

Routes must start with one `/` and cannot change the origin. Redirects are always
returned to the client; there is no redirect-following option. The filter never
retries a backend request.

The controller builder configures behavior for one feature:

| Method | Purpose |
| --- | --- |
| `get/post/put/delete($action, $path)` | Map one Yii action to an outgoing method/path. Each action may appear once. |
| `route($action, $method, $path, $fromBody = [])` | Register GET, HEAD, POST, PUT, PATCH or DELETE, with optional body bindings. |
| `uppercase($identifier = null, $except = [])` | Enable conventional names and the Yii response envelope. An omitted identifier comes from the controller contract; missing both is a configuration error. |
| `using($contract, $except = [])` | Connect native parsing and errors. The native model supplies the primary key for naming, query, and response conventions; `except` excludes actions from those mappings. |
| `within($nativeParent)` | Resolve a parent required by a nested route through the contract's scoped model. Requires `using()`. |
| `request()` | Configure input names and values, a reader, a bypass predicate, route parameters, semantic adaptation, structural guards, and native checks. See [request guards](api-adaptation.md#declare-request-guards-and-native-checks). |
| `response()` | Configure output names and values, required attribute types, omissions, projection, related resources, and record callbacks. `using` configures the JSON envelope. |
| `query()` | Configure filter, sort, and include aliases, filter values, semantic adaptation, and action-specific relation selection. See [selection rules](api-adaptation.md#keep-query-and-response-selection-together). |
| `mapQuery($mapper, $actions = [])` | Translate query fields or return `false` to keep Yii before forwarding. |
| `mapPayload($mapper, $actions = [])` | Translate the payload using a field map or callback. |
| `mapJsonResponse($mapper, $actions = [])` | Map decoded success JSON and return a PSR response. |
| `mapJsonErrors($mapper, $actions = [])` | Map upstream and package-generated errors. |
| `mapFiles($files, $actions = [])` | Map selected Yii upload names to upstream multipart fields. |
| `bypassUsing($callback)` | Decide before reading the body, from controller, lowercase action and original query. `true` keeps Yii. |
| `afterAttempt($callback)` | Observe outgoing method/path after a transport attempt, before response adaptation. |
| `build()` | Return the native Yii filter configuration. |

Payload decisions run in this order after the feature and route checks:

1. Check `query(nativeOn:)`, then the early `bypassUsing()` callback. A match does not read the body or native model.
2. Resolve the native primary key and compile request-local mappings when the contract has no explicit identifier.
3. Read the payload once and run the contract's conventional CRUD checks against that snapshot.
4. Call the configured payload predicate with the original `($payload, $actionId, $query)` values.
   Then evaluate matching structural guards. A matching bypass or failed guard keeps the request in Yii.
5. Run matching `request(before:)` checks against the original payload, in registration order.
   Exceptions reach the application's error handler.
6. Resolve body bindings and any scoped parent, map the request, and attempt forwarding if no bypass matched.

A predicate exception reaches the application error handler unchanged. It does not
select the legacy action. Payload matches use the `bypass_payload` diagnostic reason.
The [adaptation guide](api-adaptation.md#enable-the-feature-after-checking-compatibility)
includes field-presence and nested-value examples. `actions` scopes guards,
`before` checks, `lists`, `defaults`, and relation exceptions.
Input field/value maps and callbacks remain shared across actions. Repeated non-null `reader` or
`bypass` arguments replace that callback; omitted arguments retain it.

## Inline mappings

Start with [one fluent proxy](api-adaptation.md#one-fluent-proxy). Use the
[custom mapping example](api-adaptation.md#advanced-adapt-a-different-envelope) when
your contract requires different shapes. `Mapping` handles field maps, item and
collection envelopes, validation errors, and exact offset pagination.
`Json::response()` handles serialization, status, and safe response metadata.

Empty action lists define defaults. An action-specific mapping replaces its default
for that stage. Re-registering the same action replaces its previous mapping.
Action names are lowercase.

Request callbacks receive `($value, $request)`. The snapshot has original `action`,
`query`, and `payload`; errors before body reading have `payload=null`. Query mapping
receives query parameters without `r`. Query callbacks return an array or `false`.
Payload callbacks return an array, a `stdClass`, or `false`. A flat payload map creates
an object, including `{}` when empty. `false` keeps Yii before any HTTP attempt.
Exceptions do not select the old handler. Query bypass skips payload mapping.

For raw JSON, the payload snapshot has an array at the root and keeps nested objects
as `stdClass`. Existing bypass callbacks still receive their associative arrays.
Custom `request(reader: ...)` readers keep the representation they return.
Treat mapper inputs and snapshot objects as read-only. Return mapped values instead
of changing nested objects in place. The mapping helpers do not mutate their inputs.

JSON callbacks receive `($json, $response, $request)` and return `ResponseInterface`.
Objects decode as `stdClass`, lists as arrays, and large integers as strings.
Response handlers are selected using the original HTTP status. Redirects bypass
JSON mapping. Empty bodies arrive as `null`. Without a matching handler, the original
response passes through. Response-only and query-only maps do not reserialize raw
request JSON. A custom `request(reader: ...)` supplies the payload snapshot instead of the default JSON reader.

Malformed JSON and `InvalidUpstreamResponse` from structural helpers produce one
502 error before error mapping. Other callback exceptions propagate unchanged.
Neither path replays a request after forwarding. Logs distinguish upstream and
client statuses even when mapping changes the status.

`Json::response()` preserves `X-Request-Id`, `Retry-After`, and `X-RateLimit-Remaining`
and sets JSON Content-Type. It drops old compression, length, validators, and digest
metadata. Add other headers explicitly with PSR response methods. For informational statuses, 204, 205, and
304, use a null body or explicitly select a body-permitting status such as 200.

`Mapping::fields()` uses literal `SOURCE => TARGET` names. Missing fields are omitted,
null remains null, unknown fields are excluded, and duplicate destinations are errors.
Helper paths are arrays of segments, not dotted expressions. `Mapping::many()` accepts
a record callback for exceptional nested structures without a recursive schema.
`Mapping::item()` and `collection()` use `from=['data']`, `to=['data']`, and an optional
envelope. Configured total paths must exist and hold a nonnegative integer or its
correct string form. `Mapping::validationErrors()` collects unknown fields under
`_general` while keeping messages and merging aliases.

`Mapping::legacyQuery()` takes explicit filter, sort, and include maps. Its defaults
are `defaultLimit=20` and `maxLimit=100`. It returns `filter`, CSV `sort/include`, and
`page/per_page`. Unsupported query shapes or unaligned offsets return `false`.
It never rounds an offset into a different page.

## Independent read and write flags

Use two existing builders with distinct feature names and disjoint action maps.
Keep **both filters after all preceding security checks**; the final part of the
filter list is the Strangler block. No route-level flag API is required.
Set `features.catalog_read` and `features.catalog_write` to independent booleans.
All four combinations are checked by `composer example:verify`.
A skipped first filter continues to the next; it does not necessarily select Yii.
Do not map the same action in both filters: a bypass in the first could then select
the second upstream route rather than the intended legacy action.

## Headers, TLS and payloads

By default, only `Accept-Language`, `X-Request-Id` and `Idempotency-Key` are forwarded
from clients. Set `forward_headers` to the complete allowlist your API requires,
including those defaults if you still need them. `headers` contains server-owned values, which override forwarded values.
Client identity, marker, locale and token headers are never forwarded, even if listed;
transport-specific headers are excluded too.

Cookie and Authorization are excluded by default. An explicit `forward_headers`
entry can opt in only when your backend contract requires those client credentials;
this is a deliberate change from the normal Yii-owned session model. Server-owned
backend credentials belong in `headers`. Backend `Set-Cookie` is always removed.
The JSON request has its own Content-Type. If a mapping rewrites a response body,
set its Content-Type and remove obsolete ETag, compression and cache metadata; the
[catalog example](api-adaptation.md) shows an explicit response-header policy.

Certificate verification defaults to `true`. For an internal certificate authority,
set `verify_ssl` to an absolute CA-bundle path. Disable it only deliberately in a
local development environment. Positive finite `timeout` and `connect_timeout`
values are in seconds; invalid values, including zero, use the defaults of 10 and 3.

The default payload reader accepts a JSON object or array. An empty body means no
payload; malformed JSON, `null` and scalar JSON return `400` before forwarding.
This validation applies to every mapped action, including GET and HEAD: their bodies
can supply route or bypass fields but are not sent upstream. Do not send irrelevant
bodies on read requests. Without a payload mapper or custom reader, write JSON bytes are
preserved. Request mappings receive PHP arrays with large integers represented as strings;
they own the re-encoded object's shape, including empty objects.
`request(reader: ...)` reads form fields explicitly and must return an array; validate its
structure in the preceding filters. The filter uses one payload snapshot for the
bypass decision and forwarding. Use [`mapFiles()`](api-adaptation.md#file-uploads) for
multipart uploads. Arbitrary raw request bodies are outside this JSON proxy's scope.

## Rollout and rollback

Keep flags disabled until application contract tests pass. Deploy the reviewed
`composer.lock` with `composer install`. Follow the [operations guide](operations.md)
for data ownership, configuration reload, and uncertain writes.

Disabling a flag affects subsequent requests. It does not cancel in-flight requests,
reverse backend writes, or make incompatible data safe for Yii.

## Logging and callbacks

Set `params.strangler.log_requests` to `true`. Add a Yii log route if the application
does not already collect the category, and include `log` in the application's
existing `preload` list so its request-end handler is registered:

```php
'preload' => ['log'], // Merge with any existing preload components.
'components' => [
    'log' => [
        'class' => 'CLogRouter',
        'routes' => [[
            'class' => 'CFileLogRoute',
            'levels' => 'info, warning, error',
            'categories' => 'strangler',
            'logFile' => 'strangler.log',
        ]],
    ],
],
```

With request diagnostics enabled, each candidate records its feature/action,
selection and reason. Reasons distinguish a disabled flag, unmapped action,
callback bypass and payload bypass. `selected=false` refers to this candidate;
another Strangler filter may still forward the request. Use your existing `X-Request-Id` to correlate systems;
Strangler forwards a valid single-line value and includes it with the feature in
its logs. It does not create a new correlation service. Attempt logs record backend
and client statuses separately, so a mapping's status change remains visible.
Ordinary logs use route templates and safe error categories rather than resolved
identifiers or raw transport exception messages. Payloads and tokens are not logged.
See [operations](operations.md) for troubleshooting and recovery.

The optional `afterAttempt($method, $path)` callback runs after a transport attempt,
including failure, **before response mapping**. It does not receive the final client
response. Keep it small: a thrown callback exception reaches Yii's error
handler and can prevent delivery of a response even after a successful backend
write. Test these hooks and never replay writes to compensate for their errors.

For an optional metrics sink, catch failures inside your application callback so
that a successful backend write can still return its confirmation:

```php
->afterAttempt(static function (string $method, string $path) use ($metrics): void {
    try {
        $metrics->record($method); // Avoid high-cardinality resolved IDs in metric labels.
    } catch (Throwable) {
        Yii::log('Optional metrics sink unavailable', CLogger::LEVEL_WARNING, 'strangler');
    }
})
```

The callback receives the resolved path for compatibility; keep it out of ordinary
logs and metric labels. Mandatory hooks retain the existing exception contract.
Do not put an essential business write in this hook: the backend transaction owns
business invariants. The runnable example verifies both callback failure policies.
There is no new observer API or silent exception suppression in Strangler.

## Before deployment

The [catalog configuration check](../examples/catalog/README.md#verify-and-investigate)
validates declarations without business HTTP calls. Adapt it to your host, then
check reachability separately through a documented read-only endpoint. Neither
check proves that a write will succeed.

## Local development

To work on the package beside a Yii application, clone the repository and add a
Composer path repository:

```shell
git clone --branch 5.3.0 --depth 1 https://github.com/cosmira/yii1-strangler-proxy.git ../yii1-strangler-proxy
composer config repositories.yii1-strangler-proxy path ../yii1-strangler-proxy
composer require cosmira/yii1-strangler-proxy:5.3.0
```

A path repository is for development; deploy the tagged VCS dependency and the
application's lock file. If you deploy a path checkout, pin its reviewed commit
explicitly and ensure that exact checkout is present during installation.

From the package checkout, install dependencies and run the checks:

```shell
composer install
composer test
composer test:types
composer test:rector
composer test:soda
vendor/bin/pint --test
composer test:coverage
composer test:mutation
```

Coverage checks require PCOV or Xdebug; mutation testing is slower than the unit suite.
CI also runs Soda, Markdown and spelling checks. Source line, method and class
coverage must reach 100%; whole-package mutation score and covered mutation score
must each reach at least 90% using the configured default mutator set.
Mutation runs enforce per-test time limits, including failures caused by nontermination;
the HTTP catalog example retains its larger integration-test budget. These gates
protect this library; each application still needs its own migration contract tests.

### Dependency boundaries

Soda is a Composer development dependency. `composer test:soda` uses the installed
version, locally and in GitHub Actions; no separate checkout of Soda is required.

`ForbiddenDependencies` in `soda.php` enforces these directions:

| Source | Forbidden dependencies | Responsibility |
| --- | --- | --- |
| `Cosmira\Strangler` | `App`, application `strangler`, `Illuminate`, `Symfony`, package tests | The reusable Yii package must not import its consuming application, another application framework, or test fixtures. |
| `Cosmira\Strangler\Schema` | Guzzle, PSR HTTP, package internals, `CController`, `CActiveRecord`, `Yii`, `ControllerContract`, `Strangler`, `Query` | Naming and value transforms remain independent of HTTP transport and native controller access. |
| `Cosmira\Strangler\Response` | `CController`, `CActiveRecord`, `Yii`, `ControllerContract`, `StranglerProxy`, `YiiRuntime`, `Strangler`, `Query`, package internals | Response graphs and envelopes consume pure data without calling the native controller or forwarding requests. |
| `Cosmira\Strangler\Internal\Payload` | `GuzzleHttp`, `Psr\Http` | Body bindings transform PHP/JSON data without sending requests or reading HTTP messages. |

Naming, includes, scalar formatting, and pure data mappings live in
`Cosmira\Strangler\Schema`. Response graphs and JSON envelope helpers live in
`Cosmira\Strangler\Response`. Structural response exceptions live in
`Cosmira\Strangler\Exception`. These capabilities do not depend on the controller runtime.

The payload boundary contains `BodyParameters`, an internal implementation detail;
HTTP orchestration may call it. `Internal\Http\HttpHeaders` owns protocol header
rules shared by the transport and emitter; it is not a supported application API. Other mapping
APIs can use PSR response messages: response adaptation is part of their contract.
Existing cycle checks remain enabled without changing their module configuration.
They also reject a reverse dependency from payload parsing to the package runtime,
because orchestration already depends on the payload namespace.

The configured dependency rules include the native Yii entry points used by the
controller runtime. Together with cycle checks, they keep schema and response
transforms independent of that runtime. Application callbacks still need review
for authorization, side effects, and access to native data.

`NoStaticMutableProperties` rejects static properties and local static variables,
keeping configuration and schema caches scoped to their owning instance.
`NoVacuousDocblocks` rejects descriptions that only repeat a member name;
DocBlocks must explain a contract, a constraint, or information absent from its type.
