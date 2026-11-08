# Yii 1 Strangler Proxy

[![Tests][tests-badge]][tests-workflow]
[![PHP 8.2–8.5][php-badge]](composer.json)
[![MIT license][license-badge]](LICENSE)

Move Yii 1.1 API actions to a new backend without changing the existing client.
Keep authentication, sessions, and access checks in Yii. One fluent filter forwards
enabled actions and translates differences between the two API contracts.
Unmapped actions and disabled features continue to run in Yii.

Requires **PHP 8.2+ and Yii 1.1.31+**. The package supports JSON APIs, form-to-JSON
mapping, and selected multipart uploads. Responses are buffered, including binary
responses; this is not a streaming proxy.

- [Installation](#installation)
- [Forward your first action](#forward-your-first-action)
- [Describe contract differences](#when-the-apis-differ)
- [Run the complete example](#run-the-complete-example)
- [Documentation](#documentation)

## Installation

Install the package in your Yii application:

```shell
composer require cosmira/yii1-strangler-proxy:^2.0
```

Load Composer's `vendor/autoload.php` before the Yii bootstrap. Commit your
application's `composer.lock` and use `composer install` for deployments.
For working on the package itself, see [local development](docs/integration.md#local-development).

## Forward your first action

In your application's Yii configuration array, add `params.strangler`.
This example uses an existing backend endpoint returning `{"ID":42,"NAME":"Desk"}`:

```php
'params' => [
    'strangler' => [
        'base_uri' => 'https://backend.example',
        'timeout' => 10,
        'connect_timeout' => 3,
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

The builder method sets the **outgoing** HTTP method; it does not restrict the incoming
method. Write actions still require preceding HTTP verb, CSRF, and resource-access checks.

> Only checks completed before Strangler protect forwarded requests. Checks inside
> the action or `beforeAction()` are skipped. `afterAction()` and previous filters'
> `postFilter()` do not run when Strangler ends the request. Review inherited hooks
> and cleanup before enabling the feature.

First verify that direct backend calls without the trusted token fail, and that
all Yii access checks run before Strangler. Then set `params.strangler.features.catalog` to the
boolean `true`, reload your application's configuration and, with a permitted
legacy session, request the existing API:

```shell
curl -i --cookie yii-session.txt 'https://legacy.example/index.php?r=catalog/view&id=42'
```

Expect the backend body `{"ID":42,"NAME":"Desk"}`, its status, and `X-Strangler: 1`.
Set `params.strangler.features.catalog` to `false` and repeat: Yii should run `actionView()` instead.
Configure `base_uri` as a trusted HTTP(S) origin with no path, query or credentials;
put the complete backend path in each route. Redirects are returned without following
them. GET and HEAD send query parameters; POST, PUT, PATCH and DELETE also send JSON.
Missing URL parameters or invalid JSON return `400` before contacting the backend.

## When the APIs differ

Describe only the differences from ordinary uppercase/lowercase names:

```php
use Cosmira\Strangler\Strangler;

Strangler::proxy('catalog')
    ->uppercase('ITEM_ID')
    ->response(fields: ['updated_at' => 'CHANGE_DATE'])
    ->get('get', '/api/items')
    ->get('view', '/api/items/{id}')
    ->post('create', '/api/items')
    ->put('edit', '/api/items/{id}')
    ->delete('delete', '/api/items/{id}')
    ->build();
```

This convention expects backend records under `data`. For the `view` action,
`{"data":{"id":42,"updated_at":"2026-11-08"}}` becomes
`{"state":"data","data":{"ITEM_ID":42,"CHANGE_DATE":"2026-11-08"}}`.
Lists also require `meta.total`. These are the convention's defaults; the previous
pass-through example did not require an envelope. See
[response configuration](docs/api-adaptation.md#response-defaults) to change them.

Ordinary response attributes become uppercase; input, filter and sort names become
lowercase. Do not repeat backend permission lists in the proxy: keep input and
resource access checks before Strangler and validate the new API independently.
Response exceptions do not change input names. Use `request(fields: [...])` for an actual
input naming difference, and explicit stage callbacks for different semantics.
`mapQuery()`, `mapPayload()`, and `mapJsonResponse()` replace their stage,
regardless of fluent call order.

A parent formerly supplied in the body can select the new route:

```php
Strangler::proxy('directory')
    ->uppercase('PERSON_ID')
    ->post('create', '/groups/{group}/people', fromBody: [
        'group' => 'GROUPS.*.GROUP_ID',
    ])
    ->build();
```

For a legacy create body:

```json
{"GROUPS":[{"GROUP_ID":7}],"DISPLAY_NAME":"Ada"}
```

The proxy sends `POST /groups/7/people` with this JSON body:

```json
{"display_name":"Ada"}
```

The wildcard requires exactly one list item. Missing, invalid, conflicting or
ambiguous bindings keep the request in Yii before HTTP. Only selected leaves and
containers made empty by their removal are consumed; neighboring metadata is kept.
The original callback context still contains the original body.
See [advanced mappings](docs/api-adaptation.md) for response graphs and real legacy
exceptions. The package owns JSON, headers and a single HTTP attempt.

## Run the complete example

The [catalog example](examples/catalog/README.md) includes a Yii application and a
backend. It demonstrates reads, writes, JSON and form input, access checks, and
independent read and write flags. Both applications use one SQLite dataset.

To run it outside your application, install `pdo_sqlite` and clone the package:

```shell
git clone https://github.com/cosmira/yii1-strangler-proxy.git
cd yii1-strangler-proxy
composer install
composer example:verify
```

The verifier starts local servers, compares legacy and proxied responses, and
removes its temporary data. It also checks denied access, tenant isolation,
read-after-write, and backend idempotency. A successful run exits with code 0 and
prints `All catalog contracts passed.` followed by the check count. No production
service is contacted.

## Sessions and user context

Sessions stay in Yii. The package sends the current identity as `X-User-Id` and
language as `X-Strangler-Locale`. Identity headers do not supply roles, tenant
context, or resource permissions. Configure a [trusted channel](docs/integration.md#trusted-channel)
and keep access checks before the proxy.

Client Cookie and Authorization headers are not forwarded by default. Backend
Set-Cookie headers are always removed. `X-Strangler: 1` identifies a handled
response; it is not proof of authentication.

## Feature flags and errors

Only boolean `true` enables a feature. Missing flags and unmapped actions stay in
Yii. Changing a flag affects routing after configuration reload; it does not undo
backend writes or restore an old schema.

**The proxy never retries requests or returns to Yii after forwarding.** A backend
may commit a write before its response is lost. Transport failures return `503`;
backend errors are returned to the client. Error mappings can adapt them to the
existing client's format. See [rollout and recovery](docs/operations.md).

## File uploads

Use [`mapFiles()`](docs/api-adaptation.md#file-uploads) to forward selected uploads
as multipart requests. File names, scalar fields, and backend validation are
described in the adaptation guide.

## Documentation

| Guide | What it covers |
| --- | --- |
| [API adaptation](docs/api-adaptation.md) | Naming conventions, query and body differences, response graphs, and uploads. |
| [Integration](docs/integration.md) | Security filters, trusted headers, configuration, callbacks, and development checks. |
| [Operations](docs/operations.md) | Deployment, feature flags, diagnostics, rollback, and uncertain writes. |
| [Runnable catalog](examples/catalog/README.md) | A complete local migration with native and proxied actions. |

For package development, follow the [local checks](docs/integration.md#local-development).
Report bugs or propose changes in [GitHub issues](https://github.com/cosmira/yii1-strangler-proxy/issues).

[tests-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml/badge.svg?branch=main
[tests-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml
[php-badge]: https://img.shields.io/badge/PHP-8.2--8.5-777BB4?logo=php&logoColor=white
[license-badge]: https://img.shields.io/github/license/cosmira/yii1-strangler-proxy
