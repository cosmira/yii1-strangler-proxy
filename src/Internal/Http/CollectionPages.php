<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Internal\Http;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Response\Json;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Assemble the requested native offset window from validated Laravel paginator pages.
 *
 * @internal
 *
 * @phpstan-type Options array{
 *     timeout: float, connect_timeout: float, query: array<array-key, mixed>, ...
 * }
 */
final class CollectionPages
{
    /**
     * Records retained until every upstream page succeeds.
     *
     * @var list<mixed>
     */
    private array $records = [];

    /**
     * Total fixed by the first validated paginator page.
     */
    private ?int $total = null;

    /**
     * Final page fixed by the initial total and requested page size.
     */
    private ?int $lastPage = null;

    /**
     * Numeric page expected from the next upstream response.
     */
    private int $page;

    /**
     * Retain the native offset separately from the aligned upstream page.
     */
    private function __construct(
        /**
         * Native offset into the full collection.
         */
        private readonly int $start,
        /**
         * Number of records requested in each upstream page.
         */
        private readonly int $pageSize,
        private readonly ?int $limit,
    ) {
        $this->page = intdiv($start, $pageSize) + 1;
    }

    /**
     * Translate unlimited reads and positive limits that cross an upstream page boundary.
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, ?int $pageSize): ?self
    {
        $start = filter_var($query['start'] ?? 0, FILTER_VALIDATE_INT);
        $limit = filter_var($query['limit'] ?? -1, FILTER_VALIDATE_INT);
        if ($start === false || $start < -1 || $limit === false || $limit === 0 || $limit < -1) {
            return null;
        }
        $start = max(0, $start);
        if ($limit > 0) {
            if ($start % $limit === 0) {
                return null;
            }
            $pageSize = $limit;
        }
        if ($pageSize === null || intdiv($start, $pageSize) === PHP_INT_MAX) {
            return null;
        }

        return new self($start, $pageSize, $limit === -1 ? null : $limit);
    }

    /**
     * Align the first upstream page while retaining the original offset for final slicing.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    public function query(array $query): array
    {
        return array_replace($query, [
            'start' => $this->start - $this->start % $this->pageSize,
            'limit' => $this->pageSize,
        ]);
    }

    /**
     * Apply this page's numeric coordinates and the remaining transport budget.
     *
     * @param Options $options
     *
     * @return Options|null
     */
    public function options(array $options, float $remaining): ?array
    {
        if ($remaining <= 0) {
            return null;
        }
        $query = $options['query'];
        unset($query['start'], $query['limit']);
        $options['query'] = array_replace($query, [
            'page' => $this->page, 'per_page' => $this->pageSize,
        ]);
        $options['timeout'] = $remaining;
        $options['connect_timeout'] = min($options['connect_timeout'], $remaining);

        return $options;
    }

    /**
     * Normalize collection protocol failures while preserving ordinary upstream errors.
     */
    public function response(ResponseInterface $response): ?ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        try {
            return $this->append($response);
        } catch (InvalidUpstreamResponse) {
            return Json::response($response, [
                'state' => 'error', 'error' => 'invalid_upstream_response',
            ], 502);
        }
    }

    /**
     * Return a complete response, or null when the next numeric page is required.
     */
    private function append(ResponseInterface $response): ?ResponseInterface
    {
        [$json, $data] = $this->decode($response);
        if (! property_exists($json, 'meta') && $this->total === null) {
            return $this->plainResponse($response, $json, $data);
        }
        $this->validatePage($json->meta ?? null, count($data));
        array_push($this->records, ...$data);
        $skip = $this->start % $this->pageSize;
        if ($this->page < $this->lastPage && ($this->limit === null || count($this->records) - $skip < $this->limit)) {
            $this->page++;

            return null;
        }
        $json->data = array_slice($this->records, $skip, $this->limit);

        return Json::response($response, $json);
    }

    /**
     * Decode and validate the response envelope before interpreting pagination.
     *
     * @return array{stdClass, list<mixed>}
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $json = json_decode(
                (string) $response->getBody(), flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new InvalidUpstreamResponse('Invalid collection JSON.', previous: $exception);
        }
        if (! $json instanceof stdClass) {
            throw new InvalidUpstreamResponse('Expected a collection object.');
        }
        $data = $json->data ?? null;
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidUpstreamResponse('Expected a collection data list.');
        }

        return [$json, $data];
    }

    /**
     * Retain plain list support only when no continuation is advertised.
     *
     * @param list<mixed> $data
     */
    private function plainResponse(
        ResponseInterface $response, stdClass $json, array $data,
    ): ResponseInterface {
        $links = $json->links ?? null;
        if ($links !== null && ! $links instanceof stdClass) {
            throw new InvalidUpstreamResponse('Invalid collection links.');
        }
        if (($links->next ?? null) !== null) {
            throw new InvalidUpstreamResponse(
                'Collection continuation has no pagination metadata.',
            );
        }
        $json->meta = (object) ['total' => count($data)];
        $json->data = array_slice($data, $this->start, $this->limit);

        return Json::response($response, $json);
    }

    /**
     * Reject shifted, incomplete or changing paginator plans before requesting another page.
     */
    private function validatePage(mixed $meta, int $count): void
    {
        if (! $meta instanceof stdClass) {
            throw new InvalidUpstreamResponse('Invalid collection pagination metadata.');
        }
        $current = $this->number($meta, 'current_page');
        $last = $this->number($meta, 'last_page');
        $size = $this->number($meta, 'per_page');
        $total = $this->number($meta, 'total');
        if ($total < 0) {
            throw new InvalidUpstreamResponse('Invalid collection total.');
        }
        $expectedLast = $total === 0 ? 1 : intdiv($total - 1, $this->pageSize) + 1;
        if ([$current, $size, $last] !== [$this->page, $this->pageSize, $expectedLast]) {
            throw new InvalidUpstreamResponse('Inconsistent collection pagination metadata.');
        }
        if ($this->total !== null && [$this->total, $this->lastPage] !== [$total, $last]) {
            throw new InvalidUpstreamResponse('Collection pagination changed between pages.');
        }
        $offset = ($this->page - 1) * $this->pageSize;
        $expectedCount = min($this->pageSize, max(0, $total - $offset));
        if ($count !== $expectedCount) {
            throw new InvalidUpstreamResponse('Incomplete upstream collection page.');
        }
        $this->total = $total;
        $this->lastPage = $last;
    }

    /**
     * Parse paginator integers without coercing malformed upstream JSON.
     */
    private function number(stdClass $meta, string $name): int
    {
        $value = $meta->$name ?? null;
        if (! is_int($value)) {
            throw new InvalidUpstreamResponse('Missing collection pagination metadata.');
        }

        return $value;
    }
}
