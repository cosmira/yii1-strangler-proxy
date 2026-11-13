<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Internal\Http\CollectionPages;
use Cosmira\Strangler\Internal\Payload\BodyParameters;
use Cosmira\Strangler\Response\Json;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use UnexpectedValueException;

/**
 * Execute one request's inline mappings while preserving the original request snapshot.
 *
 * @internal
 *
 * @phpstan-type RouteBody callable(
 *     array<array-key, mixed>
 * ): (array<array-key, mixed>|stdClass|false)
 * @phpstan-type RequestCheck callable(array<array-key, mixed>, string, CController): void
 * @phpstan-type Context array{
 *     action: string, query: array<array-key, mixed>, payload: array<array-key, mixed>|null,
 *     identifier?: string, responseFields?: array<string, string>,
 *     errorFields?: array<string, string>,
 *     method?: string, requestMethod?: string
 * }
 * @phpstan-type QueryMapper array<array-key, string>|callable(
 *     array<array-key, mixed>, Context
 * ): (array<array-key, mixed>|false)
 * @phpstan-type PayloadMapper Convention|CustomPayloadMapper
 * @phpstan-type DefaultPayload callable(
 *     array<array-key, mixed>|stdClass|false
 * ): (array<array-key, mixed>|stdClass|false)
 * @phpstan-type CustomPayloadMapper array<array-key, string>|callable(
 *     array<array-key, mixed>, Context, DefaultPayload
 * ): (array<array-key, mixed>|stdClass|false)
 * @phpstan-type ResponseMapper callable(mixed, ResponseInterface, Context): ResponseInterface
 * @phpstan-type RequestValues array{
 *     query: array<array-key, mixed>, payload: array<array-key, mixed>, body: string|null
 * }
 * @phpstan-type Settings array{
 *     query?: array<string, QueryMapper>, payload?: array<string, PayloadMapper>,
 *     response?: array<string, ResponseMapper>, errors?: array<string, ResponseMapper>,
 *     responseFields?: array<string, string>, errorFields?: array<string, string>,
 *     completeCollections?: array<string, int>, convention?: Convention
 * }
 */
final readonly class InlineMapping
{
    /**
     * Capture the configured handlers and original request values.
     *
     * @param Settings                              $mappings
     * @param Context                               $context
     * @param array<array-key, mixed>|stdClass|null $originalBody
     */
    public function __construct(
        private array $mappings,
        private array $context,
        private array|stdClass|null $originalBody = null,
    ) {}

    /**
     * Complete the request snapshot with an already validated body when available.
     *
     * @param array<array-key, mixed> $payload
     */
    public function withPayload(array $payload, ?string $body = null): self
    {
        $source = BodyParameters::source($payload, $body);

        return new self(
            $this->mappings,
            array_replace($this->context, ['payload' => (array) $source]),
            $source,
        );
    }

    /**
     * Replace shared or action-specific registrations for one mapping stage.
     *
     * @template T
     *
     * @param array<string, T> $registered
     * @param T                $mapper
     * @param list<string>     $actions
     *
     * @return array<string, T>
     */
    public static function register(array $registered, mixed $mapper, array $actions): array
    {
        foreach ($actions ?: ['*'] as $action) {
            if ($action === '') {
                throw new InvalidArgumentException('Mapping actions must not be empty.');
            }
            $registered[strtolower($action)] = $mapper;
        }

        return $registered;
    }

    /**
     * Isolate each action-scoped native check from mutations by preceding callbacks.
     *
     * @param list<array{actions: list<string>, check: RequestCheck}> $checks
     *
     * @return Closure(array<array-key, mixed>, string, CController): void
     */
    public static function beforeRequest(array $checks): Closure
    {
        return static function (
            array $payload, string $action, CController $controller,
        ) use ($checks): void {
            $snapshot = new self([], ['action' => $action, 'query' => [], 'payload' => $payload]);
            foreach ($checks as $entry) {
                if ($entry['actions'] === [] || in_array($action, $entry['actions'], true)) {
                    ($entry['check'])($snapshot->context()['payload'] ?? [], $action, $controller);
                }
            }
        };
    }

    /**
     * Preserve explicit global or action callbacks regardless of fluent call order.
     *
     * @template T
     *
     * @param array<string, T> $registered
     * @param T                $mapper
     * @param list<string>     $actions
     *
     * @return array<string, T>
     */
    public static function defaults(array $registered, mixed $mapper, array $actions): array
    {
        return isset($registered['*'])
            ? $registered
            : array_replace(array_fill_keys($actions, $mapper), $registered);
    }

    /**
     * Resolve route values once against the original body, before custom mappings.
     *
     * @param array<string, string|list<string>> $bindings
     * @param array<array-key, mixed>            $fallback
     *
     * @return array{
     *     parameters: array<string, int|string>, payload: array<array-key, mixed>|stdClass
     * }|false
     */
    public function bindBodyParameters(array $bindings, array $fallback): array|false
    {
        return BodyParameters::resolve(
            $bindings, $this->originalBody ?? $this->context['payload'] ?? $fallback,
        );
    }

    /**
     * Supply the original snapshot to route resolvers independently of query transformation.
     *
     * @return Context
     */
    public function context(): array
    {
        $context = $this->context;
        if (isset($this->mappings['responseFields'])) {
            $context['responseFields'] = $this->mappings['responseFields'];
        }
        if (isset($this->mappings['errorFields'])) {
            $context['errorFields'] = array_replace(
                $this->mappings['errorFields'],
                ($this->mappings['convention'] ?? null)?->validationFields(
                    $context['payload'] ?? [],
                ) ?? [],
            );
        }
        $context['query'] = self::copy($this->context['query']);
        $context['payload'] = $this->context['payload'] === null
            ? null : self::copy($this->context['payload']);

        return $context;
    }

    /**
     * Map supported request values before any upstream attempt.
     *
     * @param array<array-key, mixed>               $query
     * @param array<array-key, mixed>               $payload
     * @param array<array-key, mixed>|stdClass|null $remaining
     *
     * @return RequestValues|false
     */
    public function request(
        array $query, array $payload, ?string $body, array|stdClass|null $remaining = null,
    ): array|false {
        $mappedQuery = $this->query($query);
        if ($mappedQuery === false) {
            return false;
        }
        $mappedPayload = $this->payload($payload, $body, $remaining);
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
        $collectionQuery = $this->collection()?->query($query) ?? $query;
        $mapper = $this->select($this->mappings['query'] ?? []);
        if ($mapper === null) {
            return $collectionQuery;
        }
        $mapped = is_callable($mapper)
            ? $mapper(self::copy($collectionQuery), $this->context())
            : Mapping::fields($collectionQuery, $mapper);
        if ($mapped === false || is_array($mapped)) {
            return $mapped;
        }

        throw new UnexpectedValueException('Query mappings must return an array or false.');
    }

    /**
     * Select complete reads from the original query, independently of mapped page numbers.
     */
    public function collection(): ?CollectionPages
    {
        $collections = $this->mappings['completeCollections'] ?? [];
        $action = $this->context['action'];
        $size = $collections[$action] ?? null;
        if (($size === null && ($action !== 'get' || ! isset($this->mappings['convention'])))
            || ($this->context['method'] ?? '') !== 'GET' || ($this->context['requestMethod'] ?? '') !== 'GET') {
            return null;
        }

        return CollectionPages::fromQuery($this->context['query'], $size);
    }

    /**
     * Translate a selected payload while retaining untouched body bytes.
     *
     * @param array<array-key, mixed>               $payload
     * @param array<array-key, mixed>|stdClass|null $remaining
     *
     * @return array{payload: array<array-key, mixed>, body: string|null}|false
     */
    private function payload(
        array $payload, ?string $body, array|stdClass|null $remaining,
    ): array|false {
        $original = $this->context['payload'] ?? $payload;
        $selectedPayload = $payload;
        $selectedBody = $body;
        if ($remaining !== null) {
            $original = (array) $remaining;
            $selectedPayload = $original;
            $selectedBody = json_encode(
                $remaining, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
        }
        $mapper = $this->select($this->mappings['payload'] ?? []);
        if ($mapper === null) {
            return ['payload' => $selectedPayload, 'body' => $selectedBody];
        }
        $mapped = $this->mapPayload(
            $mapper, $original, $remaining ?? $this->originalBody ?? $selectedPayload,
        );
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
     * Give conventions the JSON shape and custom mappings an isolated legacy snapshot.
     *
     * @param PayloadMapper                    $mapper
     * @param array<array-key, mixed>          $original
     * @param array<array-key, mixed>|stdClass $source
     *
     * @return mixed The caller validates custom callback results before encoding.
     */
    private function mapPayload(
        array|callable|Convention $mapper, array $original, array|stdClass $source,
    ): mixed {
        if ($mapper instanceof Convention) {
            return $mapper->payload($source, $this->context());
        }

        return is_callable($mapper)
            ? $mapper(self::copy($original), $this->context(), $this->defaultPayload(...))
            : Mapping::fields((object) $original, $mapper);
    }

    /**
     * Reuse configured naming and values after a route changes the body structure.
     *
     * @param array<array-key, mixed>|stdClass|false $payload
     *
     * @return array<array-key, mixed>|stdClass|false
     */
    private function defaultPayload(array|stdClass|false $payload): array|stdClass|false
    {
        if ($payload === false) {
            return false;
        }
        $convention = $this->mappings['convention'] ?? null;

        return $convention?->payload($payload, $this->context()) ?? $payload;
    }

    /**
     * Apply the selected JSON handler while preserving unconfigured responses.
     */
    public function response(ResponseInterface $response): ResponseInterface
    {
        $handler = $this->responseHandler($response->getStatusCode());
        if ($handler === null) {
            return $response;
        }

        return $this->mapResponse($response, $handler);
    }

    /**
     * Identify responses that need JSON adaptation before materializing them again.
     */
    public function mapsResponse(int $status): bool
    {
        return $this->responseHandler($status) !== null;
    }

    /**
     * Select the configured response stage while leaving redirects untouched.
     *
     * @return ResponseMapper|null
     */
    private function responseHandler(int $status): ?callable
    {
        return $status >= 300 && $status < 400
            ? null
            : $this->select($this->mappings[$status >= 400 ? 'errors' : 'response'] ?? []);
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
            return $handler($json, $response, $this->context());
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
                $response, $this->context(),
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

    /**
     * Isolate JSON containers between callbacks without changing arrays into objects.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function copy(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::copy($value);
            } elseif ($value instanceof stdClass) {
                $values[$key] = (object) self::copy((array) $value);
            }
        }

        return $values;
    }
}
