# Yii 1 Strangler Proxy

Documentation for **6.0.0**.

Route `body` callbacks now run before automatic field and value mapping.
Callbacks from 5.5 that called the supplied mapper must return the modified body directly.
New query and response body modifiers are available in 6.0.0.

[![Tests][tests-badge]][tests-workflow]
[![PHP 8.2–8.5][php-badge]](composer.json)
[![MIT license][license-badge]](LICENSE)

Move Yii 1.1 API actions to a new backend without changing the existing client.
Keep authentication, sessions, and access checks in Yii. One fluent filter forwards
enabled actions and translates differences between the two API contracts.
Unmapped actions and disabled features continue to run in Yii.

Requires **PHP 8.2+ and Yii 1.1.31+**. Supports JSON APIs, form-to-JSON
mapping, and selected multipart uploads.

- [Installation](#installation)
- [Forward your first action](#forward-your-first-action)
- [Describe contract differences](#when-the-apis-differ)
- [Connect existing REST controllers](#connect-existing-rest-controllers)
- [Run the complete example](#run-the-complete-example)
- [Documentation](#documentation)

## Installation

Install the package in your Yii application:

```shell
composer require cosmira/yii1-strangler-proxy:^6.0
```

Load Composer's `vendor/autoload.php` before the Yii bootstrap. Commit your
application's `composer.lock` and use `composer install` for deployments.

## Forward your first action

In your application's Yii configuration array, add `params.strangler`.
This example uses an existing backend endpoint returning `{"ID":42,"NAME":"Desk"}`:

```php
'params' => [
    'strangler' => [
        'base_uri' => 'https://backend.example',
        'features' => ['catalog' => false],
    ],
],
```

HTTPS certificates are verified by default. Protect the backend with a trusted
channel before enabling the feature; the [integration guide](docs/integration.md)
includes a server-owned token example.

Import the builder at the top of your existing controller file:

```php
use Cosmira\Strangler\Strangler;
```

Append this entry to the array returned by `filters()`, after the existing
security filters. Keep those entries and `actionView()` in place.

```php
Strangler::proxy('catalog')
    ->get('view', '/api/items/{id}')
    ->build(),
```

Routes set outgoing HTTP methods. Authentication, incoming-method checks, CSRF,
and resource access must run in preceding Yii filters.

Forwarding skips the action and its lifecycle hooks. See
[access checks and cleanup](docs/integration.md#access-checks-and-yii-hooks).

First verify that direct backend calls without the trusted token fail, and that
all Yii access checks run before Strangler. Then set `params.strangler.features.catalog` to the
boolean `true`, reload your application's configuration and, with a permitted
legacy session, request the existing API:

```shell
curl -i --cookie yii-session.txt 'https://legacy.example/index.php?r=catalog/view&id=42'
```

Expect the backend body `{"ID":42,"NAME":"Desk"}`, its status, and `X-Strangler: 1`.
Set `params.strangler.features.catalog` to `false` and repeat: Yii should run `actionView()` instead.
Use an HTTP(S) origin for `base_uri`, without a path, query, or credentials.
Put the backend path in each route.

## When the APIs differ

The catalog examples use `id` and `name` under `data`.

Without a controller contract, `uppercase()` enables naming, query translation,
and the Yii response envelope. Its argument names the native primary key:

```php
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->fields(['name' => 'TITLE'])
    ->get('get', '/api/items')
    ->get('view', '/api/items/{id}')
    ->post('create', '/api/items')
    ->put('edit', '/api/items/{id}')
    ->delete('delete', '/api/items/{id}')
    ->build();
```

This convention expects backend records under `data`. For the `view` action,
`{"data":{"id":42,"name":"Desk"}}` becomes
`{"state":"data","data":{"ITEM_ID":42,"TITLE":"Desk"}}`.
Lists also require `meta.total`. Ordinary response attributes become uppercase;
input, filter, and sort names become lowercase. Describe only naming or structural
differences. The proxy does not restrict names to the backend's supported fields.

`fields()` shares aliases across writes, responses, filters, ordering and validation
field names. Use `request(fields: [...])` or `response(fields: [...])` for directional
exceptions. A controller contract supplies the native error envelope; otherwise
[configure error mapping](docs/api-adaptation.md#preserve-errors-and-headers).

See [API adaptation](docs/api-adaptation.md) for body-to-route bindings, relations,
request guards, and custom envelopes.

## Connect existing REST controllers

If your controllers share a model accessor and a parsed request envelope, connect
those existing methods once. Put this class where Composer or Yii can autoload it.
This example assumes the controllers expose `getModel()` and `data()`:

```php
use Cosmira\Strangler\ControllerContract;

class ApiContract extends ControllerContract
{
    public function model(CController $controller): CActiveRecord
    {
        return $controller->getModel();
    }

    public function payload(CController $controller): array
    {
        return $controller->data();
    }
}
```

Register the contract once in the existing application configuration:

```php
'params' => [
    'strangler' => [
        'controller_contract' => ApiContract::class,
    ],
],
```

Keep the other Strangler settings. Each controller declares only routes and
resource-specific differences:

```php
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->get('view', '/api/items/{id}')
    ->build();
```

The configured contract enables naming, query, and response conventions. The package reads the
native model's primary key when handling a selected request, after preceding Yii
filters. Building the filter does not read the model. Conventional
`get`, `view`, `create`, `edit`, and `delete` actions also check the incoming method
and request shape. Authentication, permissions, CSRF, and
application-specific rules remain in your existing Yii filters.

Shared names and value conversions belong in the same
[controller contract](docs/api-adaptation.md#share-a-controller-contract).

### Reuse a related resource

Declare a resource's differences in its existing controller filter. Related records
can reuse that declaration instead of repeating field maps or branching on record IDs:

```php
return Strangler::proxy('catalog')
    ->response(relations: [
        'items' => ItemController::class,
        'owner' => ['controller' => PersonController::class, 'many' => false],
    ])
    ->get('view', '/api/catalogs/{id}')
    ->build();
```

Related controllers supply their own Strangler record declarations. Resolution is
lazy and does not run actions or `init()`. See
[requirements and overrides](docs/api-adaptation.md#reuse-related-resource-declarations).

## Run the complete example

The [catalog example](examples/catalog/README.md) runs Yii and a backend against
one SQLite dataset. It requires `pdo_sqlite`:

```shell
git clone --branch 5.4.0 --depth 1 https://github.com/cosmira/yii1-strangler-proxy.git
cd yii1-strangler-proxy
composer install
composer example:verify
```

The verifier starts local servers, checks legacy and proxied contracts, and removes
its temporary data. Success prints `All catalog contracts passed.` and a check count.

## Before enabling a feature

Only boolean `true` enables a feature. Missing flags and unmapped actions stay in
Yii. Disabling a flag changes routing; it does not undo backend writes.

The proxy sends `X-User-Id` and `X-Strangler-Locale` over your
[trusted channel](docs/integration.md#trusted-channel). These headers do not grant
resource permissions. Client Cookie and Authorization are not forwarded by default.
Backend Set-Cookie headers are removed.

**There are no retries or fallback to Yii after forwarding.** A transport failure
returns `503`, even if the backend committed a write. Follow the
[operations guide](docs/operations.md) for uncertain outcomes.

For files, use [`mapFiles()`](docs/api-adaptation.md#file-uploads). Large uploads
require cURL for bounded PHP memory; binary downloads must bypass JSON mapping.
See [file performance](docs/performance.md) for buffering limits and measurements.

## Documentation

| Guide | What it covers |
| --- | --- |
| [API adaptation](docs/api-adaptation.md) | Naming conventions, query and body differences, response graphs, and uploads. |
| [Compatibility checklist](docs/compatibility.md) | Request guards, callback scope, related resources, and checks before enabling a feature. |
| [Integration](docs/integration.md) | Security filters, trusted headers, configuration, callbacks, and development checks. |
| [File performance](docs/performance.md) | Upload transport requirements, buffering, and reproducible measurements. |
| [Operations](docs/operations.md) | Deployment, feature flags, diagnostics, rollback, and uncertain writes. |
| [Runnable catalog](examples/catalog/README.md) | A complete local migration with native and proxied actions. |

For package development, follow the [local checks](docs/integration.md#local-development).
Report bugs or propose changes in [GitHub issues](https://github.com/cosmira/yii1-strangler-proxy/issues).

[tests-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml/badge.svg?branch=main
[tests-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml
[php-badge]: https://img.shields.io/badge/PHP-8.2--8.5-777BB4?logo=php&logoColor=white
[license-badge]: https://img.shields.io/github/license/cosmira/yii1-strangler-proxy
