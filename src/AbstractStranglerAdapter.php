<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

/**
 * Extend this adapter when only part of the API contract needs translation.
 */
abstract class AbstractStranglerAdapter implements StranglerAdapterInterface
{
    /**
     * Preserve query parameters unless an adapter overrides their translation.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    public function transformQuery(string $actionId, array $query): array
    {
        return $query;
    }

    /**
     * Preserve the request payload unless an adapter overrides its translation.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function transformPayload(string $actionId, array $payload): array
    {
        return $payload;
    }

    /**
     * Preserve the upstream response unless an adapter overrides its translation.
     *
     * @param array<string, array<string>> $headers
     *
     * @return array{status: int, body: string, headers: array<string, array<string>>}
     */
    public function transformResponse(
        string $actionId,
        int $status,
        string $body,
        array $headers,
    ): array {
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}
