# Adapt different API contracts inside Yii

Keep the old client contract in Yii. The backend can keep its new API.
Use inline mappings for ordinary translations and retain `usingAdapter()` for an
existing or unusually complex adapter. Do not combine both approaches in one filter.

## Define and connect one feature

This example translates `start/limit` to `page/per_page`, nests write fields, and
restores the legacy item, list, and error envelopes. It needs no adapter class.
Place the resulting filter after the existing access, method, CSRF, and input checks.

The old write body is `{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}`.
The backend accepts `{"product":{"title":"Desk","enabled":true},"category":{"id":7}}`.
It returns `{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}`.
Lists use `{"data":[...],"meta":{"total":50}}`.

```php
use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\Json;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Strangler;
use Psr\Http\Message\ResponseInterface;

$fields = ['id' => 'ID', 'title' => 'NAME'];
$errorFields = ['title' => 'NAME', 'product.title' => 'NAME',
    'category.id' => 'CATEGORY_ID', 'product.enabled' => 'ACTIVE'];
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
    ->bodyIdentifier('ID')
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
    ->bypassPayloadUsing(static fn (array $payload, string $action): bool =>
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

## Use maps for ordinary fields

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
rounding. Add filters, sorts, and includes as explicit name maps.

## Preserve errors and headers

`mapJsonErrors()` receives backend errors and synthetic package errors. Validation
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
selected. `payloadUsing()` retains its existing custom-reader behavior.

## Keep routing and security explicit

The first argument of `Strangler::proxy()` is a feature key. In
`put('update', '/api/items/{id}')`, the action name belongs to Yii and the path and
HTTP method belong to the backend. Keep incoming method and CSRF rules in preceding
Yii filters. A route must start with one `/`; it cannot change the upstream origin.

`bodyIdentifier('ID')` captures the original body identifier before mapping. Removing
`ID` from the outgoing body still resolves `/api/items/42`. The request `id` wins.
Reject conflicting identifiers in the preceding validation filter before its
resource-access check. Unresolved placeholders return 400 without contacting the backend.

For legacy form fields, read the same validated input as the preceding filter:

```php
->payloadUsing(static fn (CController $controller): array => $controller->validatedInput)
```

This turns validated form values into upstream JSON. It does not forward files.
The [runnable catalog](../examples/catalog/README.md) includes JSON and form variants,
ACL, CSRF, tenant isolation, idempotency, and a real HTTP contract verifier.

## Retain existing adapters where needed

Existing `StranglerAdapterInterface` implementations remain supported. Extend
`AbstractStranglerAdapter` and override `transformQuery()`, `transformPayload()`, or
`transformResponse()` for a complex existing integration. Connect it with
`usingAdapter($instance)`, an autoloadable class name, or Yii component configuration.
Do not combine that option with inline mappings in the same filter.

## Enable the feature after checking compatibility

Keep the feature off until the client's requests and responses match through both
executors. Verify list, item, writes, errors, permissions, relations, and timeouts.
Unmapped actions and deliberate bypasses stay in Yii before forwarding.

A simple payload-field bypass remains available:

```php
->bypassWhenPayloadHas('update', ['LEGACY_ONLY'])
```

For a nested value, use `bypassPayloadUsing()` as in the complete example. Both use
the original payload snapshot. An early `bypassUsing()` match does not read the body.
The payload rules run after one read and before mapping. A matching field bypass
short-circuits the predicate. Exceptions do not select the old handler.

Roll back by disabling the feature for subsequent requests. Neither disabling a flag
nor returning `false` from a request mapper replays an operation already sent upstream.
