<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Internal\Payload\BodyParameters;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Response\ResponseGraph;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;

/**
 * Build the Yii filter configuration for migrated actions.
 *
 * @phpstan-import-type Relation from ResponseGraph
 * @phpstan-import-type AttributeMapper from ResponseGraph
 * @phpstan-import-type Finalizer from ResponseGraph
 * @phpstan-import-type Projection from ResponseGraph
 * @phpstan-import-type Settings from InlineMapping
 * @phpstan-import-type QueryMapper from InlineMapping
 * @phpstan-import-type CustomPayloadMapper from InlineMapping
 * @phpstan-import-type ResponseMapper from InlineMapping
 *
 * @phpstan-type Route array{
 *     method: string, path: string, fromBody?: array<string, string|list<string>>
 * }
 * @phpstan-type Config array{
 *     feature?: string,
 *     routes?: array<string, Route>,
 *     mappings?: Settings,
 *     bodyIdentifier?: string,
 *     controllerContract?: ControllerContract,
 *     nativeParent?: string,
 *     payloadReader?: callable(CController): array<array-key, mixed>,
 *     routeParameters?: callable(array{action: string, query: array<array-key, mixed>,
 *         payload: array<array-key, mixed>|null}): (array<string, mixed>|false),
 *     bypass?: callable(CController, string, array<array-key, mixed>): bool,
 *     bypassPayload?: callable(array<array-key, mixed>, string, array<array-key, mixed>): bool,
 *     afterAttempt?: callable(string, string): void,
 *     bypassPayloadFields?: array<string, list<string>>,
 *     files?: array<string, array<string, string>>
 * }
 */
final class Strangler
{
    /**
     * Feature settings and callbacks collected by the builder.
     *
     * @var Config
     */
    private array $config;

    /**
     * Upstream routes indexed by normalized legacy action names.
     *
     * @var array<string, Route>
     */
    private array $routes = [];

    /**
     * Primary legacy body identifier; null leaves the original passthrough contract.
     */
    private ?string $identifier = null;
    /**
     * Keep convention activation independent of an identifier supplied by the controller contract.
     */
    private bool $isConventionEnabled = false;

    /**
     * Actions excluded from automatic request and JSON response mappings.
     *
     * @var list<string>
     */
    private array $except = [];
    /**
     * Response-only naming exceptions.
     *
     * @var array<string, string>
     */
    private array $responseFields = [];
    /**
     * Request-only naming exceptions.
     *
     * @var array<string, string>
     */
    private array $requestFields = [];

    /**
     * Filter-only exceptions keyed by the upstream model attribute.
     *
     * @var array<string, string>
     */
    private array $filterAliases = [];

    /**
     * Sort-only exceptions independent of writes and response names.
     *
     * @var array<string, string>
     */
    private array $sortAliases = [];

    /**
     * Filter value differences independent of filter naming.
     *
     * @var array<string, callable(mixed): mixed>
     */
    private array $queryValues = [];

    /**
     * Request value differences keyed by their upstream attribute names.
     *
     * @var array<string, callable(mixed): mixed>
     */
    private array $requestValues = [];

    /**
     * Shape differences that reuse the compiled input naming convention.
     *
     * @var (Closure(
     *     array<array-key, mixed>, array{action: string}, Convention
     * ): (array<array-key, mixed>|\stdClass|false))|null
     */
    private ?Closure $payloadAdapter = null;
    /**
     * Response value differences keyed by their upstream attribute names.
     *
     * @var array<string, callable(mixed): mixed>
     */
    private array $responseValues = [];

    /**
     * Shared graph edges declared once for root and nested records.
     *
     * @var array<string, Relation>
     */
    private array $relations = [];

    /**
     * Upstream metadata omitted throughout the graph.
     *
     * @var list<string>
     */
    private array $excludedAttributes = [];

    /**
     * Attributes selected through the native projection.
     *
     * @var list<string>
     */
    private array $optionalAttributes = [];

    /**
     * Application value normalization applied before naming.
     *
     * @var list<AttributeMapper>
     */
    private array $attributeMappers = [];

    /**
     * Derived values appended after related records are mapped.
     *
     * @var list<Finalizer>
     */
    private array $recordFinalizers = [];

    /**
     * Root selection; nested records inherit their portion of this tree.
     *
     * @var (Closure(array<array-key, mixed>): ?array<string, mixed>)|null
     */
    private ?Closure $projection = null;

    /**
     * Supported relation differences; null keeps ordinary include names automatic.
     *
     * @var array<string, string>|null
     */
    private ?array $includes = null;

    /**
     * Application query rules that reuse the compiled naming convention.
     *
     * @var Closure(
     *     array<array-key, mixed>, array{action: string}, Query
     * ): (array<array-key, mixed>|false)|null
     */
    private ?Closure $queryAdapter = null;

    /**
     * Response graph and envelope differences applied to the compiled defaults.
     *
     * @var list<Closure(JsonResource): JsonResource>
     */
    private array $responseConfigurators = [];

    /**
     * Initialize the filter builder.
     */
    private function __construct(string $feature)
    {
        $this->config = ['feature' => $feature, 'routes' => []];
    }

    /**
     * Start a builder for the named feature flag.
     */
    public static function proxy(string $feature): self
    {
        return new self($feature);
    }

    /**
     * Connect the application's controller conventions to the package runtime.
     */
    public function using(ControllerContract $contract): self
    {
        $this->config['controllerContract'] = $contract;

        return $this;
    }

    /**
     * Resolve a nested route's parent through the native model scope.
     */
    public function within(string $nativeParent): self
    {
        if ($nativeParent === '') {
            throw new InvalidArgumentException('A native parent attribute is required.');
        }
        $this->config['nativeParent'] = $nativeParent;

        return $this;
    }

    /**
     * Enable ordinary uppercase/lowercase names and the Yii success envelope.
     *
     * @param list<string> $except Actions with a different transport contract,
     *                             such as binary photos.
     */
    public function uppercase(?string $identifier = null, array $except = []): self
    {
        $this->isConventionEnabled = true;
        $this->identifier = $identifier;
        $this->except = array_map(strtolower(...), $except);

        return $this;
    }

    /**
     * Declare input naming, shape and value differences without replacing ordinary translation.
     *
     * @param array<string, string>                                 $fields
     * @param array<string, callable(mixed): mixed>                 $values
     * @param (callable(CController): array<array-key, mixed>)|null $reader
     * @param (callable(
     *     array<array-key, mixed>, string, array<array-key, mixed>
     * ): bool)|null $bypass
     * @param (callable(
     *     array{
     *         action: string, query: array<array-key, mixed>,
     *         payload: array<array-key, mixed>|null
     *     }
     * ): (array<string, mixed>|false))|null $parameters
     * @param (callable(
     *     array<array-key, mixed>, array{action: string}, Convention
     * ): (array<array-key, mixed>|\stdClass|false))|null $using
     */
    public function request(
        array $fields = [], array $values = [], ?callable $reader = null,
        ?callable $bypass = null, ?callable $parameters = null, ?callable $using = null,
    ): self {
        foreach ($fields as $source => $target) {
            self::validateFieldMap([$source => $target]);
        }
        self::validateFormatters($values);
        $this->requestFields = array_replace($this->requestFields, $fields);
        $this->requestValues = array_replace($this->requestValues, $values);

        if ($reader !== null) {
            $this->config['payloadReader'] = $reader;
        }
        if ($bypass !== null) {
            $this->config['bypassPayload'] = $bypass;
        }
        if ($parameters !== null) {
            $this->config['routeParameters'] = $parameters;
        }

        if ($using !== null) {
            $this->payloadAdapter = $using(...);
        }

        return $this;
    }

    /**
     * Extend output naming, values and the current response graph in registration order.
     *
     * @param array<string, string>                       $fields     Upstream-to-legacy names.
     * @param array<string, callable(mixed): mixed>       $values
     * @param (callable(JsonResource): JsonResource)|null $using
     * @param list<string>                                $except
     * @param list<string>                                $optional
     * @param AttributeMapper|null                        $attributes
     * @param Finalizer|null                              $after
     * @param Projection|null                             $projection
     * @param array<string, Relation>                     $relations
     */
    public function response(
        array $fields = [], array $values = [], ?callable $using = null,
        array $except = [], array $optional = [], ?callable $attributes = null,
        ?callable $after = null, ?callable $projection = null, array $relations = [],
    ): self {
        self::validateFieldMap($fields);
        self::validateFormatters($values);
        $this->responseFields = array_replace($this->responseFields, $fields);
        $this->responseValues = array_replace($this->responseValues, $values);
        $this->relations = array_replace($this->relations, $relations);
        $this->excludedAttributes = array_values(array_unique([
            ...$this->excludedAttributes, ...$except,
        ]));
        $this->optionalAttributes = array_values(array_unique([
            ...$this->optionalAttributes, ...$optional,
        ]));
        if ($attributes !== null) {
            $this->attributeMappers[] = $attributes;
        }
        if ($after !== null) {
            $this->recordFinalizers[] = $after;
        }
        if ($projection !== null) {
            $this->projection = $projection(...);
        }
        if ($using !== null) {
            $this->responseConfigurators[] = $using(...);
        }

        return $this;
    }

    /**
     * Declare query differences while retaining automatic ordinary field names.
     *
     * @param array<string, callable(mixed): mixed> $values
     * @param array<string, string>                 $filters  Upstream filter aliases.
     * @param array<string, string>                 $sorts    Upstream sort aliases.
     * @param array<string, string>|null            $includes
     * @param callable(
     *     array<array-key, mixed>, array{action: string}, Query
     * ): (array<array-key, mixed>|false)|null $using
     */
    public function query(
        array $filters = [], array $sorts = [], ?array $includes = null, ?callable $using = null,
        array $values = [],
    ): self {
        foreach ([$filters, $sorts] as $aliases) {
            foreach ($aliases as $field => $name) {
                if ($field === '' || $name === '') {
                    throw new InvalidArgumentException('Query aliases require nonempty names.');
                }
            }
        }
        self::validateFormatters($values);
        $this->queryValues = array_replace($this->queryValues, $values);
        $this->filterAliases = array_replace($this->filterAliases, $filters);
        $this->sortAliases = array_replace($this->sortAliases, $sorts);
        if ($includes !== null) {
            Mapping::legacyQuery([], includes: $includes);
            $this->includes = $includes;
        }
        if ($using !== null) {
            $this->queryAdapter = $using(...);
        }

        return $this;
    }

    /**
     * Configure the query mapping for selected actions or all actions.
     *
     * @param QueryMapper  $mapper
     * @param list<string> $actions
     */
    public function mapQuery(array|callable $mapper, array $actions = []): self
    {
        self::validateFieldMap($mapper);
        $mappings = $this->config['mappings'] ?? [];
        $mappings['query'] = self::registerMapping($mappings['query'] ?? [], $mapper, $actions);
        $this->config['mappings'] = $mappings;

        return $this;
    }

    /**
     * Configure the payload mapping for selected actions or all actions.
     *
     * @param CustomPayloadMapper $mapper
     * @param list<string>        $actions
     */
    public function mapPayload(array|callable $mapper, array $actions = []): self
    {
        self::validateFieldMap($mapper);
        $mappings = $this->config['mappings'] ?? [];
        $mappings['payload'] = self::registerMapping($mappings['payload'] ?? [], $mapper, $actions);
        $this->config['mappings'] = $mappings;

        return $this;
    }

    /**
     * Configure the response mapping for selected actions or all actions.
     *
     * @param ResponseMapper $mapper
     * @param list<string>   $actions
     */
    public function mapJsonResponse(callable $mapper, array $actions = []): self
    {
        $mappings = $this->config['mappings'] ?? [];
        $mappings['response'] = self::registerMapping(
            $mappings['response'] ?? [], $mapper, $actions,
        );
        $this->config['mappings'] = $mappings;

        return $this;
    }

    /**
     * Configure the errors mapping for selected actions or all actions.
     *
     * @param ResponseMapper $mapper
     * @param list<string>   $actions
     */
    public function mapJsonErrors(callable $mapper, array $actions = []): self
    {
        $mappings = $this->config['mappings'] ?? [];
        $mappings['errors'] = self::registerMapping($mappings['errors'] ?? [], $mapper, $actions);
        $this->config['mappings'] = $mappings;

        return $this;
    }

    /**
     * Forward selected Yii uploads as named multipart fields.
     *
     * @param array<string, mixed> $files   Yii upload names mapped to upstream names.
     * @param list<string>         $actions
     */
    public function mapFiles(array $files, array $actions = []): self
    {
        $names = [];
        foreach ($files as $source => $target) {
            if ($source === '' || ! is_string($target) || $target === '') {
                throw new InvalidArgumentException(
                    'File mappings require nonempty upload and field names.',
                );
            }
            $names[$source] = $target;
        }
        $this->config['files'] = self::registerMapping(
            $this->config['files'] ?? [],
            $names,
            $actions,
        );

        return $this;
    }

    /**
     * Keep matching requests on the legacy implementation.
     *
     * @param callable(CController, string, array<array-key, mixed>): bool $bypass
     */
    public function bypassUsing(callable $bypass): self
    {
        $this->config['bypass'] = $bypass;

        return $this;
    }

    /**
     * Run after each transport attempt, before translating the response, including failures.
     *
     * @param callable(string, string): void $callback
     */
    public function afterAttempt(callable $callback): self
    {
        $this->config['afterAttempt'] = $callback;

        return $this;
    }

    /**
     * Map a legacy action to an upstream GET route.
     *
     * @param array<string, string|list<string>> $fromBody
     */
    public function get(string $action, string $path, array $fromBody = []): self
    {
        return $this->route($action, 'GET', $path, $fromBody);
    }

    /**
     * Map a legacy action to an upstream POST route.
     *
     * @param array<string, string|list<string>> $fromBody
     */
    public function post(string $action, string $path, array $fromBody = []): self
    {
        return $this->route($action, 'POST', $path, $fromBody);
    }

    /**
     * Map a legacy action to an upstream PUT route.
     *
     * @param array<string, string|list<string>> $fromBody
     */
    public function put(string $action, string $path, array $fromBody = []): self
    {
        return $this->route($action, 'PUT', $path, $fromBody);
    }

    /**
     * Map a legacy action to an upstream DELETE route.
     *
     * @param array<string, string|list<string>> $fromBody
     */
    public function delete(string $action, string $path, array $fromBody = []): self
    {
        return $this->route($action, 'DELETE', $path, $fromBody);
    }

    /**
     * Export the Yii filter definition with its routes and bypass fields.
     *
     * @return array{0: class-string<StranglerFilter>, config: Config}
     */
    public function build(): array
    {
        $config = $this->compiled();
        $contract = $config['controllerContract'] ?? null;
        if ($contract !== null) {
            $mappings = $config['mappings'] ?? [];
            $mappings['errors'] = ($mappings['errors'] ?? []) + ['*' => $contract->error(...)];
            $config['mappings'] = $mappings;
        }
        if (isset($config['nativeParent']) && ! isset($config['controllerContract'])) {
            throw new InvalidArgumentException('within() requires a controller contract.');
        }

        return [StranglerFilter::class, 'config' => ['routes' => $this->routes] + $config];
    }

    /**
     * Compile convention defaults after all routes and explicit callbacks are known.
     *
     * @return Config
     */
    private function compiled(): array
    {
        if (! $this->isConventionEnabled) {
            $fieldSettings = [
                $this->responseFields, $this->requestFields, $this->requestValues,
                $this->responseValues, $this->filterAliases, $this->sortAliases,
                $this->responseConfigurators, $this->relations, $this->excludedAttributes,
                $this->optionalAttributes, $this->attributeMappers, $this->recordFinalizers,
                $this->includes !== null, $this->queryAdapter !== null, $this->projection !== null,
                $this->payloadAdapter !== null, $this->queryValues,
            ];
            if (array_filter($fieldSettings) !== []) {
                throw new InvalidArgumentException('Field exceptions require uppercase().');
            }

            return $this->config;
        }

        $identifier = $this->nativeIdentifier();
        $convention = $this->convention($identifier);
        $actions = array_values(array_diff(array_keys($this->routes), $this->except));
        $query = $convention->queryMapper()->values($this->queryValues);
        if ($this->includes !== null) {
            $query = $query->includes($this->includes);
        }
        $adapter = $this->queryAdapter;
        $queryMapper = $adapter === null ? $query : $query->adapt($adapter);
        $mappings = $this->config['mappings'] ?? [];
        $mappings['query'] = self::defaults($mappings['query'] ?? [], $queryMapper, $actions);
        $payloadAdapter = $this->payloadAdapter;
        $payloadMapper = $payloadAdapter === null
            ? $convention : $convention->adapt($payloadAdapter);
        $mappings['payload'] = self::defaults($mappings['payload'] ?? [], $payloadMapper, $actions);
        $mappings['response'] = self::defaults(
            $mappings['response'] ?? [], $convention->response(...), $actions,
        );

        return array_replace($this->config, [
            'bodyIdentifier' => $identifier, 'mappings' => $mappings,
        ]);
    }

    /**
     * Resolve declared metadata without instantiating a native model during filter construction.
     */
    private function nativeIdentifier(): string
    {
        $identifier = $this->identifier
            ?? ($this->config['controllerContract'] ?? null)?->identifier();
        if ($identifier === null || $identifier === '') {
            throw new InvalidArgumentException(
                'uppercase() requires a native identifier or controller contract identifier.',
            );
        }

        return $identifier;
    }

    /**
     * Bind application extension points once, leaving mapping execution stateless.
     */
    private function convention(string $identifier): Convention
    {
        $contract = $this->config['controllerContract'] ?? null;
        $resultUsing = null;
        if ($contract !== null) {
            $resultUsing = $contract->responseResult(...);
        }

        return new Convention(
            identifier: $identifier,
            output: $this->resource($identifier, $contract),
            requestFields: $this->requestFields,
            requestValues: $this->requestValues,
            filterAliases: $this->filterAliases,
            sortAliases: $this->sortAliases,
            resultUsing: $resultUsing,
        );
    }

    /**
     * Compile one response graph and layer explicit resource differences over contract defaults.
     */
    private function resource(string $identifier, ?ControllerContract $contract): JsonResource
    {
        $fieldsUsing = null;
        $projection = $this->projection;
        if ($contract !== null) {
            $fieldsUsing = $contract->responseFields(...);
            $projection ??= $contract->responseProjection(...);
        }
        $graph = new ResponseGraph(
            fields: $this->responseFields,
            values: $this->responseValues,
            relations: array_replace($contract?->relations() ?? [], $this->relations),
            except: $this->excludedAttributes,
            optional: $this->optionalAttributes,
            attributes: $contract === null ? $this->attributeMappers
                : [$contract->responseAttributes(...), ...$this->attributeMappers],
            after: $contract === null ? $this->recordFinalizers
                : [$contract->responseAfter(...), ...$this->recordFinalizers],
            fieldsUsing: $fieldsUsing,
        );
        $record = $graph->record($identifier);
        if ($projection !== null) {
            $record = $record->projectionUsing($projection);
        }
        $resource = JsonResource::make()->recordUsing($record);
        foreach ($this->responseConfigurators as $configure) {
            $resource = $configure($resource);
        }

        return $resource;
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
    private static function defaults(array $registered, mixed $mapper, array $actions): array
    {
        return isset($registered['*'])
            ? $registered
            : array_replace(array_fill_keys($actions, $mapper), $registered);
    }

    /**
     * Reject invalid value converters before requests can reach the upstream service.
     *
     * @param array<string, mixed> $formatters
     */
    private static function validateFormatters(array $formatters): void
    {
        foreach ($formatters as $field => $formatter) {
            if ($field === '' || ! is_callable($formatter)) {
                throw new InvalidArgumentException(
                    'Value differences require named callable formatters.',
                );
            }
        }
    }

    /**
     * Validate field maps without invoking application callbacks.
     *
     * @param array<array-key, mixed>|callable $mapper
     */
    private static function validateFieldMap(array|callable $mapper): void
    {
        if (is_array($mapper) && ! is_callable($mapper)) {
            Mapping::fields([], $mapper);
        }
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
    private static function registerMapping(array $registered, mixed $mapper, array $actions): array
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
     * Register a route using a case-insensitive action key.
     *
     * @param array<string, string|list<string>> $fromBody
     */
    public function route(string $action, string $method, string $path, array $fromBody = []): self
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported upstream HTTP method.');
        }
        $key = strtolower($action);
        if (isset($this->routes[$key])) {
            throw new InvalidArgumentException('Duplicate Strangler action: '.$action);
        }
        if ($action === '' || $path === '') {
            throw new InvalidArgumentException('Strangler routes require an action and path.');
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Strangler routes require one leading slash.');
        }

        BodyParameters::validate($fromBody, $path);
        $route = ['method' => $method, 'path' => $path];
        if ($fromBody !== []) {
            $route['fromBody'] = $fromBody;
        }
        $this->routes[$key] = $route;

        return $this;
    }
}
