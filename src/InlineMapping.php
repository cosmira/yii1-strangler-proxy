<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use UnexpectedValueException;

/**
 * Execute one request's inline mappings without changing the adapter contract.
 *
 * @internal
 *
 * @phpstan-type Context array{
 *     action: string, query: array<array-key, mixed>, payload: array<array-key, mixed>|null
 * }
 * @phpstan-type QueryMapper array<array-key, string>|callable(
 *     array<array-key, mixed>, Context
 * ): (array<array-key, mixed>|false)
 * @phpstan-type PayloadMapper array<array-key, string>|callable(
 *     array<array-key, mixed>, Context
 * ): (array<array-key, mixed>|stdClass|false)
 * @phpstan-type ResponseMapper callable(mixed, ResponseInterface, Context): ResponseInterface
 * @phpstan-type RequestValues array{
 *     query: array<array-key, mixed>, payload: array<array-key, mixed>, body: string|null
 * }
 * @phpstan-type Settings array{
 *     query?: array<string, QueryMapper>, payload?: array<string, PayloadMapper>,
 *     response?: array<string, ResponseMapper>, errors?: array<string, ResponseMapper>
 * }
 */
final readonly class InlineMapping
{
    /**
     * Capture the configured handlers and original request values.
     *
     * @param Settings $mappings
     * @param Context  $context
     */
    public function __construct(private array $mappings, private array $context) {}

    /**
     * Complete the request snapshot with an already validated body when available.
     *
     * @param array<array-key, mixed> $payload
     */
    public function withPayload(array $payload, ?string $body = null): self
    {
        // The legacy decoder flattens objects for existing bypass callbacks.
        $snapshotPayload = $body === null
            ? $payload
            : (array) json_decode($body, flags: JSON_BIGINT_AS_STRING);

        return new self(
            $this->mappings,
            array_replace($this->context, ['payload' => $snapshotPayload]),
        );
    }

    /**
     * Map supported request values before any upstream attempt.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     *
     * @return RequestValues|false
     */
    public function request(array $query, array $payload, ?string $body): array|false
    {
        $mappedQuery = $this->query($query);
        if ($mappedQuery === false) {
            return false;
        }
        $mappedPayload = $this->payload($payload, $body);
        if ($mappedPayload === false) {
            return false;
        }

        return ['query' => $mappedQuery] + $mappedPayload;
    }

    /**
     * Translate query parameters or retain the legacy executor.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>|false
     */
    private function query(array $query): array|false
    {
        $mapper = $this->select($this->mappings['query'] ?? []);
        if ($mapper === null) {
            return $query;
        }
        $mapped = is_callable($mapper)
            ? $mapper($query, $this->context)
            : Mapping::fields($query, $mapper);
        if ($mapped === false || is_array($mapped)) {
            return $mapped;
        }

        throw new UnexpectedValueException('Query mappings must return an array or false.');
    }

    /**
     * Translate a selected payload while retaining untouched body bytes.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{payload: array<array-key, mixed>, body: string|null}|false
     */
    private function payload(array $payload, ?string $body): array|false
    {
        $mapper = $this->select($this->mappings['payload'] ?? []);
        if ($mapper === null) {
            return ['payload' => $payload, 'body' => $body];
        }
        $original = $this->context['payload'] ?? $payload;
        $mapped = is_callable($mapper)
            ? $mapper($original, $this->context)
            : Mapping::fields((object) $original, $mapper);
        if ($mapped === false) {
            return false;
        }
        if (! is_array($mapped) && ! $mapped instanceof stdClass) {
            throw new UnexpectedValueException(
                'Payload mapper must return array, stdClass or false.',
            );
        }
        $mappedBody = json_encode(
            $mapped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        $mappedPayload = $mapped instanceof stdClass ? get_object_vars($mapped) : $mapped;

        return ['payload' => $mappedPayload, 'body' => $mappedBody];
    }

    /**
     * Apply the selected JSON handler while preserving unconfigured responses.
     */
    public function response(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            return $response;
        }
        $handler = $this->select($this->mappings[$status >= 400 ? 'errors' : 'response'] ?? []);
        if ($handler === null) {
            return $response;
        }

        return $this->mapResponse($response, $handler);
    }

    /**
     * Decode selected responses and contain malformed upstream data.
     *
     * @param ResponseMapper $handler
     */
    private function mapResponse(ResponseInterface $response, callable $handler): ResponseInterface
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        try {
            $json = $body === '' ? null : json_decode(
                $body, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException) {
            return $this->mapInvalidResponse($response);
        }

        try {
            return $handler($json, $response, $this->context);
        } catch (InvalidUpstreamResponse) {
            return $status < 400
                ? $this->mapInvalidResponse($response)
                : $this->invalidResponse($response);
        }
    }

    /**
     * Produce one controlled error without recursively retrying its handler.
     */
    private function mapInvalidResponse(ResponseInterface $upstream): ResponseInterface
    {
        $response = $this->invalidResponse($upstream);
        $handler = $this->select($this->mappings['errors'] ?? []);
        if ($handler === null) {
            return $response;
        }

        try {
            return $handler(
                (object) ['state' => 'error', 'error' => 'invalid_upstream_response'],
                $response, $this->context,
            );
        } catch (InvalidUpstreamResponse) {
            return $response;
        }
    }

    /**
     * Replace unusable upstream bytes with a stable error envelope.
     */
    private function invalidResponse(ResponseInterface $upstream): ResponseInterface
    {
        return Json::response($upstream, [
            'state' => 'error', 'error' => 'invalid_upstream_response',
        ], 502);
    }

    /**
     * Prefer an action-specific handler over its shared default.
     *
     * @template T
     *
     * @param array<string, T> $handlers
     *
     * @return T|null
     */
    private function select(array $handlers): mixed
    {
        return $handlers[$this->context['action']] ?? $handlers['*'] ?? null;
    }
}
