# Adapting different API contracts

Changing the destination URL is enough only when both APIs accept the same requests
and return the same responses. When their contracts differ, provide an adapter that
translates between them. The existing client continues using the legacy API.

```text
legacy client → Yii checks → request mapping → new API
legacy client ← legacy response format ← response mapping ← new API
```

## 1. Define the mapping for one feature

For example, the legacy catalog API and its replacement have these contracts:

| Operation | Legacy API | New API |
| --- | --- | --- |
| List items | `GET /index.php?r=catalog/get&start=20&limit=10` | `GET /api/items?page=3&per_page=10` |
| Create an item | `POST /index.php?r=catalog/create` | `POST /api/items` |
| Update an item | `POST /index.php?r=catalog/update`, with `ID` in the body | `PUT /api/items/42` |

The update request sent by the existing client is:

```json
{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}
```

The new API expects the identifier in the URL and a different body structure:

```json
{"product":{"title":"Desk","enabled":true},"category":{"id":7}}
```

It responds with:

```json
{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}
```

The existing client still expects:

```json
{"success":true,"data":{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}}
```

Agree on field names, nesting, types, pagination, statuses and error formats before
enabling the feature. The examples below implement this specific catalog contract;
adapt them to your application's validated input and backend response schema.

## 2. Implement the translations

Create an application-owned **API contract adapter**. `AbstractStranglerAdapter`
implements unchanged query, payload and response behavior; override the methods
your contract needs. It implements `StranglerAdapterInterface`:

```php
use Cosmira\Strangler\AbstractStranglerAdapter;

final class CatalogApiAdapter extends AbstractStranglerAdapter
{
    public function transformQuery(string $actionId, array $query): array
    {
        if ($actionId !== 'get') {
            return [];
        }

        $limit = max(1, (int) ($query['limit'] ?? 20));
        $start = max(0, (int) ($query['start'] ?? 0));

        return [
            'page' => intdiv($start, $limit) + 1,
            'per_page' => $limit,
        ];
    }

    public function transformPayload(string $actionId, array $payload): array
    {
        if (!in_array($actionId, ['create', 'update'], true)) {
            return [];
        }

        return [
            'product' => [
                'title' => $payload['NAME'],
                'enabled' => (bool) $payload['ACTIVE'],
            ],
            'category' => ['id' => $payload['CATEGORY_ID']],
        ];
    }

    public function transformResponse(
        string $actionId,
        int $status,
        string $body,
        array $headers,
    ): array {
        // Preserve redirects and empty responses.
        if ($status < 200 || ($status >= 300 && $status < 400) || $body === '') {
            return ['status' => $status, 'body' => $body, 'headers' => $headers];
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($response)) {
            throw new UnexpectedValueException('The catalog API must return a JSON object.');
        }
        if ($status < 400 && (!isset($response['data']) || !is_array($response['data'])
            || ($actionId === 'get' && !isset($response['meta']['total'])))) {
            throw new UnexpectedValueException('The catalog API returned an unexpected schema.');
        }
        if ($status >= 400) {
            $legacy = [
                'success' => false,
                'error' => [
                    'code' => $response['code'] ?? $response['error'] ?? 'request_failed',
                    'fields' => $this->legacyErrors($response['errors'] ?? []),
                ],
            ];
        } elseif ($actionId === 'get') {
            $legacy = [
                'success' => true,
                'total' => $response['meta']['total'],
                'rows' => array_map($this->legacyItem(...), $response['data']),
            ];
        } else {
            $legacy = ['success' => true, 'data' => $this->legacyItem($response['data'])];
        }

        // Keep only metadata that remains valid after rewriting the body.
        $preserved = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), ['x-request-id', 'retry-after', 'x-ratelimit-remaining'], true)) {
                $preserved[$name] = $values;
            }
        }
        $headers = ['Content-Type' => ['application/json; charset=utf-8']] + $preserved;

        return [
            'status' => $status,
            'body' => json_encode($legacy, JSON_THROW_ON_ERROR),
            'headers' => $headers,
        ];
    }

    private function legacyErrors(array $errors): array
    {
        $names = [
            'title' => 'NAME', 'product.title' => 'NAME',
            'category.id' => 'CATEGORY_ID', 'product.enabled' => 'ACTIVE',
        ];
        $fields = [];
        foreach ($errors as $name => $messages) {
            // Unknown fields become general messages rather than invisible form errors.
            $field = $names[$name] ?? '_general';
            $fields[$field] = array_merge($fields[$field] ?? [], $messages);
        }

        return $fields;
    }

    private function legacyItem(array $item): array
    {
        if (!isset($item['id'], $item['title'], $item['enabled'], $item['category']['id'])) {
            throw new UnexpectedValueException('The catalog API returned an incomplete item.');
        }

        return [
            'ID' => $item['id'],
            'NAME' => $item['title'],
            'ACTIVE' => $item['enabled'] ? 1 : 0,
            'CATEGORY_ID' => $item['category']['id'],
        ];
    }
}
```

For a smaller change, extend the same base class and override only the relevant
method; the other two remain unchanged. No new configuration layer is required.

Each method has a distinct purpose:

- `transformQuery()` returns the query parameters the new API accepts. Here it replaces
  offset pagination with page pagination and omits the old parameters.
- `transformPayload()` returns the new JSON body. Here it renames fields, creates nested
  objects and converts legacy integer flags to booleans. `ID` is deliberately left out.
- `transformResponse()` returns the status, a **serialized response body**, and headers
  for the existing client. Here it maps both individual items and paginated lists.

For the list action, the example expects the backend to return
`{"data":[...],"meta":{"total":123}}` and returns
`{"success":true,"rows":[...],"total":123}` to the legacy client.
Action names passed to the adapter are lowercase: `get`, `create`, `update`.
Without an adapter or custom payload reader, write bodies retain their original JSON
bytes, including empty objects and large integers. With an adapter, payloads are PHP
arrays: empty objects become arrays and integers beyond PHP's range become strings.
Your adapter owns the new JSON shape; use string identifiers in both contracts when
numeric precision matters. The example also reads large backend integers as strings.

The pagination conversion is exact when `start` is a multiple of `limit`. The bypass
rule below keeps other offsets in Yii **before any upstream request**, so the example
never silently rounds to a different set of items. Alternatively, retain offset
support in the new backend.

The example emits JSON with a Content-Type header. Add any other headers required by
the legacy contract deliberately; upstream cache validators such as ETag describe the
original body and should not be reused unchanged after rewriting it.

The same method maps backend errors and package-generated errors. For example:

| Failure | Response passed to the adapter | Legacy response |
| --- | --- | --- |
| Validation | `422 {"code":"validation_failed","errors":{"title":["Required"]}}` | `422 {"success":false,"error":{"code":"validation_failed","fields":{"NAME":["Required"]}}}` |
| Timeout | `503 {"state":"error","error":"service_temporarily_unavailable"}` | `503 {"success":false,"error":{"code":"service_temporarily_unavailable","fields":[]}}` |

Field errors use the old form's names: `product.title` and `title` become `NAME`,
`category.id` becomes `CATEGORY_ID`, and `product.enabled` becomes `ACTIVE`.
Unknown fields retain their messages under `_general`; the old client must show that
entry as a general error. Known aliases merge their messages. This example expects
an error map whose values are arrays of strings; validate the backend schema in your
application if it can return other shapes. Adapt this explicit table to your own
client contract rather than inferring field names automatically.

The status remains an error. Invalid JSON or missing path parameters produce a `400`
through this response adapter too. If the configured adapter cannot be created,
Strangler returns its own configuration error because there is no adapter to call.
Malformed backend JSON, the unexpected schema rejected above, or a bug in your
adapter reaches Yii's application error handler. Configure that handler to emit the
legacy JSON error envelope, a `500` status and `Content-Type: application/json` for
API requests; it must not render Yii's HTML error page or expose exception details.
Exercise this through the real API with HTML, scalar JSON, missing `data` and a
throwing adapter. The catalog example rejects invalid responses; the host owns the
client error format. Do not report failures as success or replay failed writes.

## 3. Connect the adapter and routes

Make the adapter available through your application's autoloading. In the controller
from [Getting started](../README.md#getting-started), replace the Strangler filter entry
with this configuration, keeping it after the existing security checks:

```php
Strangler::proxy('catalog')
    ->usingAdapter(new CatalogApiAdapter())
    ->bodyIdentifier('ID')
    ->bypassUsing(static function (CController $controller, string $actionId, array $query): bool {
        $limit = max(1, (int) ($query['limit'] ?? 20));
        $start = max(0, (int) ($query['start'] ?? 0));

        return $actionId === 'get' && $start % $limit !== 0;
    })
    ->get('get', '/api/items')
    ->post('create', '/api/items')
    ->put('update', '/api/items/{id}')
    ->build()
```

An instance is simplest when dependencies are already constructed. Yii can also
create a no-argument adapter class or apply public property configuration:

```php
->usingAdapter(CatalogApiAdapter::class)
// For your own configurable adapter with a public $apiVersion property:
->usingAdapter(['class' => VersionedCatalogAdapter::class, 'apiVersion' => 'v2'])
```

Choose one form. Make each class autoloadable and ensure it implements
`StranglerAdapterInterface`; Yii configuration is not a dependency container.

The first route argument names the **Yii action**; the second is the **new API path**.
The builder method chooses the outgoing HTTP method. For example, `put('update', ...)`
turns a legacy POST to `actionUpdate` into an upstream PUT.

`bodyIdentifier('ID')` supplies `{id}` from the original payload when the Yii request
has no `id` parameter. Strangler captures it before calling the adapter, so removing
`ID` from the outgoing body still produces `/api/items/42`. The request's `id` takes
precedence. Other placeholders use the translated body, then translated query, then
original query, accepting lowercase or uppercase keys. Values are URL-encoded;
unresolved parameters and `.`/`..` values return `400` before contacting the backend.
Paths must start with one `/`; absolute URLs and `//other-host` are rejected.
Keep prefixes such as `/v2` in the route, not in `base_uri`.

If both request `id` and body `ID` are provided, reject any mismatch in the
preceding validation filter, **before its resource-access check**. Otherwise the
access check and backend could refer to different items. See the
[write-action validation example](integration.md#write-action-validation).

Each Yii action can have one route; duplicate declarations are rejected. The builder
also provides `patch()` and `head()`. These methods set the outgoing HTTP method,
while the preceding Yii filters enforce permitted incoming methods and CSRF rules.

By default, Strangler reads a JSON body. If the old client sends form fields such as
`item[ID]=42&item[NAME]=Desk&item[ACTIVE]=1&item[CATEGORY_ID]=7`, add a payload reader:

```php
->payloadUsing(static fn(CController $controller): array => $_POST['item'] ?? [])
```

This reader alone is insufficient if your validation filter still decodes raw JSON.
Use a shared application-owned validated-input reader in both the preceding filter
and `payloadUsing()`. The [runnable catalog](../examples/catalog/README.md) includes
complete JSON and `item[...]` variants, structure checks, CSRF, and conflicting IDs.
Do not combine the JSON validation snippet with the form reader unchanged.

Add this to the same builder chain, retaining its adapter and bypass rule. For flat
form fields, read `$_POST` instead of `$_POST['item']`. The reader must return an array;
validate the submitted structure in the preceding Yii filters. Form fields become
JSON upstream; this does not forward uploaded files.

## 4. Enable the feature after checking compatibility

Keep `features.catalog` disabled until the existing client works with the translated
requests and responses. Check the outgoing URL, method, query and body, then verify
success, validation errors, permissions and timeouts from the client's perspective.

The package tests execute this catalog adapter with mock HTTP responses. Before
rollout, repeat these contract checks against your application's actual client and
backend; source coverage does not prove feature compatibility.

Only add routes whose contracts you have adapted. Unmapped actions continue in Yii.
If an action has a legacy-only payload variant, keep that variant in Yii explicitly:

```php
->bypassWhenPayloadHas('update', ['LEGACY_ONLY'])
```

This decision happens before any upstream request. Turning off the feature also keeps
subsequent requests in Yii; neither mechanism retries an operation already sent upstream.
