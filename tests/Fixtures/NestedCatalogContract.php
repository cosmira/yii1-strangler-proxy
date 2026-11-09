<?php

declare(strict_types=1);

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Response\Json;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use Psr\Http\Message\ResponseInterface;

$fields = ['id' => 'ID', 'title' => 'NAME'];
$errorFields = [
    'title'           => 'NAME',
    'product.title'   => 'NAME',
    'category.id'     => 'CATEGORY_ID',
    'product.enabled' => 'ACTIVE',
];
$relations = static function (array $query): array {
    $with = $query['with'] ?? [];
    if (! is_array($with) || ! array_is_list($with)
        || array_filter($with, static fn ($name): bool => $name !== 'category')) {
        throw new InvalidArgumentException('Only with[]=category is supported.');
    }

    return $with;
};
$record = static function (mixed $item, array $request) use ($fields, $relations): object {
    if (! $item instanceof stdClass || ! isset($item->id, $item->title, $item->enabled)
        || ! ($item->category ?? null) instanceof stdClass || ! isset($item->category->id)) {
        throw new InvalidUpstreamResponse('The catalog API returned an incomplete item.');
    }
    $legacy = Mapping::fields($item, $fields);
    $legacy->ACTIVE = $item->enabled ? 1 : 0;
    $legacy->CATEGORY_ID = $item->category->id;
    /** @var array<array-key, mixed> $query */
    $query = $request['query'];
    if (in_array('category', $relations($query), true)) {
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
        'product'  => ['title' => $payload['NAME'], 'enabled' => (bool) $payload['ACTIVE']],
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
    ->mapJsonErrors(static function (mixed $json, ResponseInterface $response) use ($errorFields): ResponseInterface {
        $error = Json::record($json);

        return Json::response($response, [
            'success' => false,
            'error'   => [
                'code'   => $error['code'] ?? $error['error'] ?? 'request_failed',
                'fields' => (array) Mapping::validationErrors($error['errors'] ?? new stdClass(), $errorFields),
            ],
        ]);
    })
    ->request(bypass: static fn (array $payload, string $action): bool => $action === 'update'
        && is_array($payload['OPTIONS'] ?? null) && ($payload['OPTIONS']['MODE'] ?? null) === 'legacy')
    ->get('get', '/api/items')
    ->get('view', '/api/items/{id}')
    ->post('create', '/api/items')
    ->put('update', '/api/items/{id}');
