<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use Cosmira\Strangler\Schema\Fields;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/** Callable Yii-style resource configuration for Strangler::mapJsonResponse(). */
final class JsonResource
{
    /**
     * Field selection or application callback applied to each upstream record.
     *
     * @var array<array-key, mixed>|Fields|Record|Closure(
     *     array<array-key, mixed>|stdClass, array<array-key, mixed>
     * ): mixed
     */
    private array|Fields|Record|Closure $record;
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
     * Application contract validation applied to the final generated response.
     *
     * @var list<Closure(ResponseInterface): void>
     */
    private array $validators = [];

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
     * Select and rename response fields.
     *
     * @param array<array-key, string>|Fields $fields
     */
    public function fields(array|Fields $fields): self
    {
        $copy = clone $this;
        $names = $fields instanceof Fields ? $fields->names() : $fields;
        Mapping::fields([], $names);
        $copy->record = $fields;

        return $copy;
    }

    /**
     * Replace record transformation while retaining response settings.
     *
     * @param callable(array<array-key, mixed>|stdClass, array<array-key, mixed>): mixed $mapper
     */
    public function recordUsing(callable $mapper): self
    {
        $copy = clone $this;
        $copy->record = $mapper instanceof Record ? $mapper : Closure::fromCallable($mapper);

        return $copy;
    }

    /**
     * Extend the current conventional record instead of replacing its naming and values.
     *
     * @param callable(Record): Record $configure
     */
    public function configureRecord(callable $configure): self
    {
        if (! $this->record instanceof Record) {
            throw new InvalidArgumentException('Only conventional Record schemas can be extended.');
        }

        return $this->recordUsing($configure($this->record));
    }

    /**
     * Append a final response check while retaining previously configured checks.
     *
     * @param callable(ResponseInterface): void $validator
     */
    public function validateUsing(callable $validator): self
    {
        $copy = clone $this;
        $copy->validators[] = Closure::fromCallable($validator);

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
            return $this->validate(Json::response(
                $response, $this->responseEnvelope, $this->responseStatus ?? 200,
            ));
        }

        $mapper = match (true) {
            $this->record instanceof Fields => fn (
                array|stdClass $record,
            ): array|stdClass => Mapping::fields($record, $this->record),
            is_array($this->record)         => $this->record,
            default                         => fn (
                array|stdClass $record,
            ): mixed => ($this->record)($record, $context),
        };
        $body = in_array($context['action'], $this->collectionActions, true)
            ? Mapping::collection($json, $mapper, $this->responseEnvelope,
                totalFrom: $this->totalSource, totalTo: $this->totalTarget)
            : Mapping::item($json, $mapper, $this->responseEnvelope);

        return $this->validate(Json::response($response, $body, $this->responseStatus));
    }

    /**
     * Run application validation and retain the generated response unchanged.
     */
    private function validate(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->validators as $validate) {
            $validate($response);
        }

        return $response;
    }
}
