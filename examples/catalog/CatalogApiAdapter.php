<?php

declare(strict_types=1);

use Cosmira\Strangler\AbstractStranglerAdapter;

final class CatalogApiAdapter extends AbstractStranglerAdapter
{
    public function transformQuery(string $actionId, array $query): array
    {
        if ($actionId !== 'get') {
            return [];
        }
        $limit = max(1, (int) ($query['limit'] ?? 20));

        return ['page' => intdiv((int) ($query['start'] ?? 0), $limit) + 1, 'per_page' => $limit];
    }

    public function transformPayload(string $actionId, array $payload): array
    {
        if (! in_array($actionId, ['create', 'update'], true)) {
            return [];
        }

        return ['product' => ['title' => $payload['NAME'], 'enabled' => $payload['ACTIVE']], 'category' => ['id' => $payload['CATEGORY_ID']]];
    }

    public function transformResponse(string $actionId, int $status, string $body, array $headers): array
    {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($response)) {
            throw new UnexpectedValueException('Expected a backend object.');
        }
        if ($status >= 400) {
            $fields = [];
            $map = ['title' => 'NAME', 'product.title' => 'NAME', 'category.id' => 'CATEGORY_ID', 'product.enabled' => 'ACTIVE'];
            foreach ($response['errors'] ?? [] as $field => $messages) {
                $target = $map[$field] ?? '_general';
                foreach ((array) $messages as $message) {
                    if (is_string($message)) {
                        $fields[$target][] = $message;
                    }
                }
            }
            $result = catalogErrorBody($response['code'] ?? $response['error'] ?? 'backend_error', $fields);
        } else {
            if (! isset($response['data']) || ! is_array($response['data'])) {
                throw new UnexpectedValueException('Backend data is missing.');
            }
            $shape = json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if ($actionId === 'get' && ! is_array($shape->data)) {
                throw new UnexpectedValueException('Backend list must be an array.');
            }
            $convert = static function (array $item): array {
                if (! isset($item['id'], $item['product']['title'], $item['product']['enabled'], $item['category']['id'])) {
                    throw new UnexpectedValueException('Backend item is incomplete.');
                }

                return ['ID' => (string) $item['id'], 'NAME' => $item['product']['title'], 'ACTIVE' => (bool) $item['product']['enabled'], 'CATEGORY_ID' => (string) $item['category']['id']];
            };
            $result = $actionId === 'get'
                ? ['success' => true, 'items' => array_map($convert, $response['data']), 'total' => (int) ($response['total'] ?? 0)]
                : ['success' => true, 'item' => $convert($response['data'])];
        }

        // Rewritten bytes invalidate upstream validators and compression metadata.
        return ['status' => $status, 'body' => json_encode($result, JSON_THROW_ON_ERROR), 'headers' => ['Content-Type' => ['application/json; charset=utf-8']]];
    }
}
