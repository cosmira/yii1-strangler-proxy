<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Closure;
use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Callable Yii-style resource configuration for Strangler::mapJsonResponse().
 *
 * @phpstan-type BodyModifier callable(mixed, array{action: string, status: int, query?: array<array-key, mixed>,
 *     payload?: array<array-key, mixed>|null, identifier?: string}): mixed
 * @phpstan-type ProjectionContext array{action: string, query?: array<array-key, mixed>}
 */
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
     * Request selection resolved once before mapping the response records.
     *
     * @var (Closure(ProjectionContext): ?array<string, mixed>)|null
     */
    private ?Closure $projection = null;
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
     * Application body changes performed before field and envelope mapping.
     *
     * @var (Closure(mixed, array{action: string, status: int, query?: array<array-key, mixed>,
     *     payload?: array<array-key, mixed>|null, identifier?: string}): mixed)|null
     */
    private ?Closure $bodyModifier = null;

    /**
     * Application contract validation applied to the final generated response.
     *
     * @var list<Closure(ResponseInterface): void>
     */
    private array $validators = [];

    /**
     * Require an explicit upstream data member even for envelope-only actions.
     */
    private bool $requiresData = false;

    /**
     * Operation result paths whose empty lists become native error responses.
     *
     * @var list<array{
     *     path: list<string|int>, error: string, status: int, actions: list<string>
     * }>
     */
    private array $emptyResults = [];

    /**
     * Preserve upstream records until a field map or callback is configured.
     */
    private function __construct()
    {
        $this->record = static fn (array|stdClass $record): array|stdClass => $record;
    }

    /**
     * Configure result handling and an optional structural body modifier.
     *
     * @param BodyModifier|null                                   $body
     * @param list<string>|null                                   $empty
     * @param array<string, array{list<string|int>, string, int}> $rejectEmpty
     *
     * @return (Closure(self): self)|null
     */
    public static function outcomes(
        ?array $empty, array $rejectEmpty, ?callable $body = null,
    ): ?Closure {
        if ($empty === null && $rejectEmpty === [] && $body === null) {
            return null;
        }
        $rules = self::make()->requireData()->empty($empty ?? ['delete']);
        foreach ($rejectEmpty as $action => [$path, $error, $status]) {
            $rules = $rules->rejectEmpty($path, $error, $status, [$action]);
        }

        return static function (self $resource) use ($empty, $rules, $rejectEmpty, $body): self {
            $copy = clone $resource;
            $copy->requiresData = $copy->requiresData || $empty !== null || $rejectEmpty !== [];
            $copy->bodyModifier = $body === null ? $copy->bodyModifier : $body(...);
            if ($empty !== null) {
                $copy->emptyActions = $rules->emptyActions;
            }
            $copy->emptyResults = [...$copy->emptyResults, ...$rules->emptyResults];

            return $copy;
        };
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
     * Resolve request selection at the envelope, without changing a reusable record schema.
     *
     * @internal
     *
     * @param callable(ProjectionContext): ?array<string, mixed> $resolver
     */
    public function projectionUsing(callable $resolver): self
    {
        $copy = clone $this;
        $copy->projection = $resolver(...);

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
     * Reject successful non-204 responses that omit the upstream data member.
     */
    public function requireData(): self
    {
        $copy = clone $this;
        $copy->requiresData = true;

        return $copy;
    }

    /**
     * Translate an empty list at the exact upstream path into an operation error.
     *
     * @param list<string|int> $path
     * @param list<string>     $actions
     */
    public function rejectEmpty(array $path, string $error, int $status, array $actions = []): self
    {
        $selectedActions = array_map(strtolower(...), $actions);
        if ($path === [] || $error === '' || $status < 400 || $status > 599) {
            throw new InvalidArgumentException('Invalid empty result rejection.');
        }
        if (in_array('', $path, true)) {
            throw new InvalidArgumentException('Invalid result path segment.');
        }
        if (in_array('', $selectedActions, true)) {
            throw new InvalidArgumentException('Invalid result action.');
        }
        $copy = clone $this;
        $copy->emptyResults[] = [
            'path' => $path, 'error' => $error, 'status' => $status, 'actions' => $selectedActions,
        ];

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
     * @param array{action: string, query?: array<array-key, mixed>} $context
     */
    public function __invoke(
        mixed $json,
        ResponseInterface $response,
        array $context,
    ): ResponseInterface {
        $source = $this->bodyModifier === null ? $json : ($this->bodyModifier)(
            $json, ['status' => $response->getStatusCode()] + $context,
        );
        if ($source instanceof ResponseInterface) {
            return $this->validate($source);
        }
        $rejected = $this->operationResult($source, $response, $context['action']);
        if ($rejected !== null) {
            return $this->validate($rejected);
        }
        if ($response->getStatusCode() === 204
            || in_array($context['action'], $this->emptyActions, true)) {
            return $this->validate(Json::response(
                $response, $this->responseEnvelope, $this->responseStatus ?? 200,
            ));
        }

        $mapper = $this->recordMapper($context);
        $body = in_array($context['action'], $this->collectionActions, true)
            ? Mapping::collection($source, $mapper, $this->responseEnvelope,
                totalFrom: $this->totalSource, totalTo: $this->totalTarget)
            : Mapping::item($source, $mapper, $this->responseEnvelope);

        return $this->validate(Json::response($response, $body, $this->responseStatus));
    }

    /**
     * Validate the success envelope before evaluating action-specific empty results.
     */
    private function operationResult(
        mixed $json, ResponseInterface $response, string $action,
    ): ?ResponseInterface {
        if ($response->getStatusCode() === 204) {
            return null;
        }
        $this->validateSuccessEnvelope($json);
        foreach ($this->emptyResults as $rule) {
            if ($rule['actions'] !== [] && ! in_array($action, $rule['actions'], true)) {
                continue;
            }
            if ($this->resultValue($json, $rule['path']) === []) {
                return Json::response(
                    $response, ['state' => 'error', 'error' => $rule['error']], $rule['status'],
                );
            }
        }

        return null;
    }

    /**
     * Require the configured upstream data member before empty-action handling.
     */
    private function validateSuccessEnvelope(mixed $json): void
    {
        if ($this->requiresData
            && (! $json instanceof stdClass || ! property_exists($json, 'data'))) {
            throw new InvalidUpstreamResponse('Missing upstream response data.');
        }
    }

    /**
     * Bind the root request selection while retaining custom record transformations.
     *
     * @param array{action: string, query?: array<array-key, mixed>} $context
     *
     * @return array<array-key, mixed>|callable(array<array-key, mixed>|stdClass): mixed
     */
    private function recordMapper(array $context): array|callable
    {
        $record = $this->record;
        $resolve = $this->projection;
        $schema = $record instanceof Record && $resolve !== null
            ? $record->defaultProjectionUsing(static fn (): ?array => $resolve($context))
            : $record;

        return match (true) {
            $schema instanceof Fields => fn (
                array|stdClass $record,
            ): array|stdClass => Mapping::fields($record, $schema),
            is_array($schema)               => $schema,
            default                         => fn (
                array|stdClass $record,
            ): mixed => ($schema)($record, $context),
        };
    }

    /**
     * Traverse literal result path segments without interpreting dotted names.
     *
     * @param list<string|int> $path
     */
    private function resultValue(mixed $value, array $path): mixed
    {
        $record = $value;
        foreach ($path as $segment) {
            $record = match (true) {
                $record instanceof stdClass => $record->$segment ?? null,
                is_array($record)           => $record[$segment] ?? null,
                default                     => null,
            };
        }

        return $record;
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
