<?php

declare(strict_types=1);

use Cosmira\Strangler\Strangler;

/** Pure configuration also consumed by the deployment check. */
function catalogSettings(): array
{
    $flag = static fn (string $name): bool => filter_var(getenv($name) ?: 'false', FILTER_VALIDATE_BOOLEAN);

    return [
        'base_uri'        => getenv('CATALOG_BACKEND_URL') ?: 'http://127.0.0.1:9082',
        'timeout'         => (float) (getenv('CATALOG_TIMEOUT') ?: '1'),
        'connect_timeout' => 0.5,
        'headers'         => ['X-Strangler-Token' => getenv('STRANGLER_TOKEN') ?: 'local-demo-token'],
        'forward_headers' => ['X-Request-Id', 'Idempotency-Key'],
        'features'        => ['catalog_read' => $flag('CATALOG_READ_ENABLED'), 'catalog_write' => $flag('CATALOG_WRITE_ENABLED')],
        'log_requests'    => true,
    ];
}

/** No callback is invoked and no business adapter is constructed here. */
function catalogProxyFilters(): array
{
    $decorate = static function (Strangler $builder): Strangler {
        return $builder->usingAdapter(CatalogApiAdapter::class)
            ->payloadUsing(static fn (CController $controller): array => $controller->validatedInput)
            ->bodyIdentifier('ID')
            ->afterAttempt(static function (string $method, string $path): void {
                if (getenv('CATALOG_HOOK_FAIL') === '1') {
                    throw new RuntimeException('Mandatory application hook failed.');
                }

                try {
                    if (getenv('CATALOG_METRICS_FAIL') === '1') {
                        throw new RuntimeException('Demo metrics sink unavailable.');
                    }
                    catalogEvent('metrics', ['method' => $method]);
                } catch (Throwable) {
                    Yii::log('Optional catalog metrics unavailable.', CLogger::LEVEL_WARNING, 'strangler');
                    catalogEvent('metrics_failed');
                }
            });
    };

    return [
        $decorate(Strangler::proxy('catalog_read'))
            ->bypassUsing(static function (CController $controller, string $action, array $query): bool {
                return $action === 'get' && (int) ($query['start'] ?? 0) % max(1, (int) ($query['limit'] ?? 20)) !== 0;
            })
            ->get('view', '/api/items/{id}')->get('get', '/api/items')->build(),
        $decorate(Strangler::proxy('catalog_write'))
            ->post('create', '/api/items')->put('update', '/api/items/{id}')->build(),
    ];
}
