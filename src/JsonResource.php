<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/** Callable Yii-style resource configuration for Strangler::mapJsonResponse(). */
final class JsonResource
{
    /**
     * Field selection or application callback applied to each upstream record.
     *
     * @var array<array-key, mixed>|Closure(
     *     array<array-key, mixed>|stdClass, array<array-key, mixed>
     * ): mixed
     */
    private array|Closure $record;
    /**
     * Application-owned response fields surrounding mapped data.
     *
     * @var array<array-key, mixed>
     */
    private array $responseEnvelope = ['state' => 'data'];
    /**
     * Literal upstream path containing the collection total.
     *
     * @var array<array-key, mixed>
     */
    private array $totalSource = ['meta', 'total'];
    /**
     * Literal destination path for the translated collection total.
     *
     * @var array<array-key, mixed>
     */
    private array $totalTarget = ['meta', 'totalCount'];
    /**
     * Actions whose data must be a list rather than one record.
     *
     * @var list<string>
     */
    private array $collectionActions = ['get'];
    /**
     * Actions that return only the configured response envelope.
     *
     * @var list<string>
     */
    private array $emptyActions = ['delete'];
    /**
     * Mapped status, or null to retain the status of nonempty upstream responses.
     */
    private ?int $responseStatus = 200;
    /**
     * Source-to-target names retained for inverse write-payload translation.
     *
     * @var array<array-key, string>
     */
    private array $fieldMap = [];

    /** @var array<string, string>|null */
    private ?array $writeMap = null;
    /**
     * Upstream fields allowed to receive values from legacy write payloads.
     *
     * @var list<string>
     */
    private array $writableFields = [];

    /**
     * Preserve upstream records until a field map or callback is configured.
     */
    private function __construct()
    {
        $this->record = static fn (array|stdClass $record): array|stdClass => $record;
    }

    /**
     * Start with the common Yii REST envelope, total, action, and status defaults.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Select response fields and retain their names for inverse payload mapping.
     *
     * @param array<array-key, string>|Fields $fields
     */
    public function fields(array|Fields $fields): self
    {
        $copy = clone $this;
        $copy->writeMap = $fields instanceof Fields ? $fields->writes() : null;
        $fields = $fields instanceof Fields ? $fields->names() : $fields;
        Mapping::fields([], $fields);
        $copy->record = $fields;
        $copy->fieldMap = $fields;

        return $copy;
    }

    /**
     * Replace record transformation without changing the writable field map.
     *
     * @param callable(array<array-key, mixed>|stdClass, array<array-key, mixed>): mixed $mapper
     */
    public function recordUsing(callable $mapper): self
    {
        $copy = clone $this;
        $copy->record = Closure::fromCallable($mapper);

        return $copy;
    }

    /**
     * Replace the application-owned fields surrounding mapped data.
     *
     * @param array<array-key, mixed> $envelope
     */
    public function envelope(array $envelope): self
    {
        $copy = clone $this;
        $copy->responseEnvelope = $envelope;

        return $copy;
    }

    /**
     * Read and write collection totals through literal segment paths.
     *
     * @param array<array-key, mixed> $sourcePath
     * @param array<array-key, mixed> $targetPath
     */
    public function totals(array $sourcePath, array $targetPath): self
    {
        $copy = clone $this;
        $copy->totalSource = $sourcePath;
        $copy->totalTarget = $targetPath;

        return $copy;
    }

    /**
     * Replace the actions whose upstream data must contain a collection.
     *
     * @param list<string> $actions
     */
    public function collections(array $actions): self
    {
        $copy = clone $this;
        $copy->collectionActions = $actions;

        return $copy;
    }

    /**
     * Replace the actions that skip record mapping and return only the envelope.
     *
     * @param list<string> $actions
     */
    public function empty(array $actions): self
    {
        $copy = clone $this;
        $copy->emptyActions = $actions;

        return $copy;
    }

    /**
     * Choose a mapped status, or preserve nonempty upstream statuses with null.
     */
    public function status(?int $status): self
    {
        $copy = clone $this;
        $copy->responseStatus = $status;

        return $copy;
    }

    /**
     * Restrict inverse payload mapping to selected upstream field names.
     *
     * @param list<string> $fields Upstream field names accepted in write payloads.
     */
    public function writable(array $fields): self
    {
        $copy = clone $this;
        $copy->writableFields = $fields;

        return $copy;
    }

    /**
     * Translate writable legacy fields to upstream names while preserving null values.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function payload(array $payload): array
    {
        $fields = array_intersect_key($this->fieldMap, array_flip($this->writableFields));
        $names = $this->writeMap ?? array_flip($this->fieldMap);

        return Mapping::fields($payload, array_intersect_key($names, array_flip($fields)));
    }

    /**
     * Restore the legacy response contract or reject unusable upstream data.
     *
     * @param array{action: string} $context
     */
    public function __invoke(
        mixed $json,
        ResponseInterface $response,
        array $context,
    ): ResponseInterface {
        if ($response->getStatusCode() === 204
            || in_array($context['action'], $this->emptyActions, true)) {
            return Json::response($response, $this->responseEnvelope, $this->responseStatus ?? 200);
        }

        $mapper = is_array($this->record) ? $this->record
            : fn (array|stdClass $record): mixed => ($this->record)($record, $context);
        $body = in_array($context['action'], $this->collectionActions, true)
            ? Mapping::collection($json, $mapper, $this->responseEnvelope,
                totalFrom: $this->totalSource, totalTo: $this->totalTarget)
            : Mapping::item($json, $mapper, $this->responseEnvelope);

        return Json::response($response, $body, $this->responseStatus);
    }
}
