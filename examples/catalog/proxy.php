<?php

declare(strict_types=1);

use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\Json;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Strangler;
use Psr\Http\Message\ResponseInterface;

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

/** No callback is invoked here. */
function catalogProxyFilters(): array
{
    $item = static function (mixed $record): object {
        if (! $record instanceof stdClass || ! isset($record->id, $record->product->title, $record->product->enabled, $record->category->id)) {
            throw new InvalidUpstreamResponse('Backend item is incomplete.');
        }

        return (object) ['ID' => (string) $record->id, 'NAME' => $record->product->title,
            'ACTIVE'          => (bool) $record->product->enabled, 'CATEGORY_ID' => (string) $record->category->id];
    };
    $decorate = static function (Strangler $builder) use ($item): Strangler {
        return $builder
            ->mapQuery(static fn (array $query): array|false => Mapping::legacyQuery($query), ['get'])
            ->mapQuery(static fn (): array => [], ['view', 'create', 'update'])
            ->mapPayload(static fn (array $payload): array => [
                'product'  => ['title' => $payload['NAME'], 'enabled' => $payload['ACTIVE']],
                'category' => ['id' => $payload['CATEGORY_ID']],
            ], ['create', 'update'])
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response(
                $response,
                Mapping::collection($json, $item, ['success' => true], to: ['items'], totalFrom: ['total'], totalTo: ['total']),
            ), ['get'])
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response(
                $response, Mapping::item($json, $item, ['success' => true], to: ['item']),
            ), ['view', 'create', 'update'])
            ->mapJsonErrors(static function (mixed $json, ResponseInterface $response): ResponseInterface {
                $code = $json->code ?? $json->error ?? 'backend_error';
                $invalid = $code === 'invalid_upstream_response';
                $fields = Mapping::validationErrors($json->errors ?? new stdClass(), [
                    'title' => 'NAME', 'product.title' => 'NAME', 'category.id' => 'CATEGORY_ID', 'product.enabled' => 'ACTIVE',
                ]);

                return Json::response($response, catalogErrorBody($invalid ? 'internal_error' : $code, (array) $fields), $invalid ? 500 : null);
            })
            ->payloadUsing(reader: static fn (CController $controller): array => $controller->validatedInput)
            ->routeParametersUsing(static function (array $context): array {
                $id = $context['query']['id'] ?? $context['payload']['ID'] ?? null;

                return $id === null ? [] : ['id' => $id];
            })
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
