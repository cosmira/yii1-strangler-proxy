# Yii 1 Strangler Proxy

[![Tests][tests-badge]][tests-workflow]
[![PHP 8.2–8.5][php-badge]](composer.json)
[![MIT license][license-badge]](LICENSE)

Migrate a Yii 1.1 application to a new monolith one feature at a time.
Keep authentication, sessions and access checks in Yii; Strangler forwards enabled
actions and leaves the remaining actions in the legacy application. When the APIs
differ, an application-owned adapter preserves the existing client's contract.

Requires **PHP 8.2+ and Yii 1.1.31+**; upgrade older runtimes first.
Use it for JSON API actions. Legacy form fields can be translated to JSON; file
uploads, binary bodies and streamed responses need a separate integration.

## How it works

```text
request → controller → security filters → Strangler → new monolith
                                                   ↘ legacy action
```

After the security filters allow a request, Strangler checks the feature flag.
It forwards enabled, mapped actions; otherwise, Yii runs the legacy action.

## Getting started

Install the tagged release directly from its Git repository in your Yii application:

```shell
composer config repositories.yii1-strangler-proxy vcs https://github.com/cosmira/yii1-strangler-proxy
composer require cosmira/yii1-strangler-proxy:^0.1
```

The VCS entry is needed until the package is registered on Packagist.
Load Composer's `vendor/autoload.php` before the Yii bootstrap. Commit your
application's `composer.lock` and use `composer install` for deployments.
For working on the package itself, see [local development](docs/integration.md#local-development).

Configure an existing backend endpoint returning `{"ID":42,"NAME":"Desk"}`:

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

Add the Strangler filter block **after all security checks**, starting with one existing
read-only action:

```php
use Cosmira\Strangler\Strangler;

class CatalogController extends CController
{
    public function filters()
    {
        return [
            'initLanguage',
            'validateOpenAPI',
            'accessControl',
            Strangler::proxy('catalog') // After all security checks.
                ->get('view', '/api/items/{id}')
                ->build(),
        ];
    }

    // Keep the existing actionView() implementation for disabled features.
}
```

Use your application's actual authentication, access and input checks. The builder
method sets the **outgoing** HTTP method; it does not restrict the incoming method.
Write actions still require preceding HTTP verb, CSRF and resource-access checks.

> Only checks completed before Strangler protect forwarded requests. Checks inside
> the action or `beforeAction()` are skipped. `afterAction()` and previous filters'
> `postFilter()` do not run when Strangler ends the request. Review inherited hooks
> and cleanup before enabling the feature.

First verify that direct backend calls without the trusted token fail, and that
all Yii access checks run before Strangler. Then set `features.catalog` to the
boolean `true`, reload your application's configuration and, with a permitted
legacy session, request the existing API:

```shell
curl -i --cookie yii-session.txt 'https://legacy.example/index.php?r=catalog/view&id=42'
```

Expect the backend body `{"ID":42,"NAME":"Desk"}`, its status, and `X-Strangler: 1`.
Turn `features.catalog` off and repeat: Yii should run `actionView()` instead.
Configure `base_uri` as a trusted HTTP(S) origin with no path, query or credentials;
put the complete backend path in each route. Redirects are returned without following
them. GET and HEAD send query parameters; POST, PUT, PATCH and DELETE also send JSON.
Missing URL parameters or invalid JSON return `400` before contacting the backend.

## Run the complete example

The [catalog example](examples/catalog/README.md) supplies a real Yii application,
backend, JSON/form input, security checks and independent read/write flags.
It uses a shared SQLite dataset to demonstrate one concrete migration model.
The example additionally requires `pdo_sqlite`; the library does not require SQLite.
From this repository, after `composer install`, run:

```shell
composer example:verify
```

The verifier starts isolated local servers, checks the published example and removes
its temporary data. It compares the legacy and proxied client contracts, denied
requests, read-after-write, tenant isolation and backend idempotency.
Use its case table as a starting point for your own application's contract checks.

## When the APIs differ

Attach an **API contract adapter** with `usingAdapter()`. It translates fields,
nesting, types and responses; `payloadUsing()` reads legacy form fields.
Follow the [API adaptation guide](docs/api-adaptation.md) for a complete example
with pagination, body identifiers and both backend and transport errors.

## Sessions and user context

Strangler sends the current Yii identity as `X-User-Id` and the current language as
`X-Strangler-Locale`. Guests have an empty user identifier. Identity alone does not
provide roles, tenant context or resource permissions; define those checks for your
feature before forwarding it.

Sessions stay in Yii: client Cookie and Authorization headers are not forwarded by
default, and backend Set-Cookie headers are always removed. `X-Strangler: 1` is a
diagnostic marker. It does not prove authentication; use the
[trusted-channel instructions](docs/integration.md#trusted-channel).

## Feature flags and errors

Only the boolean `true` enables a feature. Missing flags and unmapped actions stay
in Yii. Deployment and support teams can set a flag to `false`; configuration reload
and data compatibility determine whether rollback is possible. See the
[rollout checklist](docs/integration.md#rollout-and-rollback).

**Strangler never falls back to Yii after forwarding and never retries requests.**
The backend may have completed a write before its response was lost. A second
execution could duplicate data. Backend errors are returned; transport failures
return `503`, and a missing backend returns `500`. A configured API adapter can
translate these responses into the old client's format. An unavailable adapter
returns `500` without translation. Other configuration and programming errors
reach the application's Yii error handler.

## Quick diagnostics

Handled responses include `X-Strangler: 1`. In `YII_DEBUG`, backend responses also
include `X-Strangler-Time` in milliseconds. Enable `log_requests` to record forwarding
in the Yii `strangler` category with the feature and incoming `X-Request-Id`.
With request logging enabled, candidate decisions explain disabled flags, unmapped
actions and bypass rules. With several Strangler filters, a skipped candidate can
continue to the next filter; it does not yet imply legacy execution. See [logging and callbacks](docs/integration.md#logging-and-callbacks).

## Quality checks

[![Code Coverage][coverage-badge]][coverage-workflow]
[![Mutation Testing][mutation-badge]][mutation-workflow]
[![Quality Assurance][quality-badge]][quality-workflow]
[![Coding Guidelines][style-badge]][style-workflow]
[![Markdown][markdown-badge]][markdown-workflow]
[![ShellCheck][shellcheck-badge]][shellcheck-workflow]
[![Spelling][spelling-badge]][spelling-workflow]
[![PHPStan max][phpstan-badge]](phpstan.neon)
[![Coverage gate 100%][coverage-gate-badge]][coverage-workflow]
[![MSI gate 100%][mutation-gate-badge]](infection.json)

The coverage and mutation badges describe enforced quality gates, not a guarantee
that every application's API contract is compatible.

[tests-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml/badge.svg?branch=main
[tests-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/phpunit.yml
[coverage-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/coverage.yml/badge.svg?branch=main
[coverage-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/coverage.yml
[mutation-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/mutation.yml/badge.svg?branch=main
[mutation-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/mutation.yml
[quality-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/quality.yml/badge.svg?branch=main
[quality-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/quality.yml
[style-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/code-style.yml/badge.svg?branch=main
[style-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/code-style.yml
[markdown-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/markdown.yml/badge.svg?branch=main
[markdown-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/markdown.yml
[shellcheck-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/shellcheck.yml/badge.svg?branch=main
[shellcheck-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/shellcheck.yml
[spelling-badge]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/typos.yml/badge.svg?branch=main
[spelling-workflow]: https://github.com/cosmira/yii1-strangler-proxy/actions/workflows/typos.yml
[php-badge]: https://img.shields.io/badge/PHP-8.2--8.5-777BB4?logo=php&logoColor=white
[license-badge]: https://img.shields.io/github/license/cosmira/yii1-strangler-proxy
[phpstan-badge]: https://img.shields.io/badge/PHPStan-max-brightgreen
[coverage-gate-badge]: https://img.shields.io/badge/coverage%20gate-100%25-brightgreen
[mutation-gate-badge]: https://img.shields.io/badge/MSI%20gate-100%25-brightgreen
