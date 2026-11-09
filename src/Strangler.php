<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Internal\Payload\BodyParameters;
use Cosmira\Strangler\Internal\Payload\NativeRequestGuard;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Response\Record;
use Cosmira\Strangler\Response\ResponseGraph;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Schema\Selection;
use InvalidArgumentException;

/**
 * Build the Yii filter configuration for migrated actions.
 *
 * @phpstan-import-type Relation from ResponseGraph
 * @phpstan-import-type AttributeMapper from ResponseGraph
 * @phpstan-import-type Finalizer from ResponseGraph
 * @phpstan-import-type Projection from ResponseGraph
 * @phpstan-import-type Context from InlineMapping
 * @phpstan-import-type Settings from InlineMapping
 * @phpstan-import-type QueryMapper from InlineMapping
 * @phpstan-import-type CustomPayloadMapper from InlineMapping
 * @phpstan-import-type ResponseMapper from InlineMapping
 *
 * @phpstan-type QueryAdapter callable(
 *     array<array-key, mixed>, array{action: string}, Query
 * ): (array<array-key, mixed>|false)
 * @phpstan-type PayloadReader callable(CController, string): array<array-key, mixed>
 * @phpstan-type RequestBypass callable(
 *     array<array-key, mixed>, string, array<array-key, mixed>
 * ): bool
 * @phpstan-type RouteParameters callable(Context): (array<string, mixed>|false)
 * @phpstan-type PayloadAdapter callable(
 *     array<array-key, mixed>, array{action: string}, Convention
 * ): (array<array-key, mixed>|\stdClass|false)
 *
 * @phpstan-import-type Body from BodyParameters
 *
 * @phpstan-type RequestCheck callable(array<array-key, mixed>, string, CController): void
 * @phpstan-type RelationDefaults array<string, mixed>|callable(
 *     array<array-key, mixed>
 * ): ?array<string, mixed>
 * @phpstan-type ProjectionClosure Closure(
 *     array{action: string, query?: array<array-key, mixed>}
 * ): ?array<string, mixed>
 * @phpstan-type Route array{
 *     method: string, path: string, fromBody?: array<string, string|list<string>>
 * }
 * @phpstan-type BoundConfig array{
 *     feature?: string,
 *     nativeQueryFilters?: list<string>,
 *     routes?: array<string, Route>,
 *     mappings?: Settings,
 *     bodyIdentifier?: string,
 *     controllerContract?: ControllerContract,
 *     responseRecord?: Record,
 *     nativeParent?: string,
 *     payloadReader?: callable(CController, string): array<array-key, mixed>,
 *     requestGuards?: list<NativeRequestGuard>,
 *     requestRelations?: array<string, list<string>>,
 *     beforeRequest?: callable(array<array-key, mixed>, string, CController): void,
 *     routeParameters?: callable(array{action: string, query: array<array-key, mixed>,
 *         payload: array<array-key, mixed>|null}): (array<string, mixed>|false),
 *     bypass?: callable(CController, string, array<array-key, mixed>): bool,
 *     bypassPayload?: callable(array<array-key, mixed>, string, array<array-key, mixed>): bool,
 *     afterAttempt?: callable(string, string): void,
 *     bypassPayloadFields?: array<string, list<string>>,
 *     files?: array<string, array<string, string>>
 * }
 * @phpstan-type DeferredConfig array{
 *     resolve: callable(CController): BoundConfig,
 *     feature?: string,
 *     nativeQueryFilters?: list<string>,
 *     routes: array<string, Route>,
 *     bypass?: callable(CController, string, array<array-key, mixed>): bool
 * }
 * @phpstan-type Config BoundConfig|DeferredConfig
 */
final class Strangler
{
    /**
     * Feature settings and callbacks collected by the builder.
     *
     * @var BoundConfig
     */
    private array $config;

    /**
     * Structural native-eligibility rules compiled without reading request state.
     *
     * @var list<NativeRequestGuard>
     */
    private array $requestGuards = [];

    /**
     * Native checks executed after structural guards, before mapping or HTTP.
     *
     * @var list<array{actions: list<string>, check: RequestCheck}>
     */
    private array $requestChecks = [];

    /**
     * Upstream routes indexed by normalized legacy action names.
     *
     * @var array<string, Route>
     */
    private array $routes = [];

    /**
     * Explicit native identifier; otherwise the controller model supplies it at runtime.
     */
    private ?string $identifier = null;
    /**
     * Enable standalone naming conventions without requiring a controller contract.
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
     * Action-specific input list bindings and absent-value defaults.
     *
     * @var array<string, array{lists: array<string, string>, defaults: array<string, mixed>}>
     */
    private array $requestRules = [];

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
     * @var ProjectionClosure|null
     */
    private ?Closure $projection = null;

    /**
     * One action policy shared by the outgoing query and root response projection.
     */
    private ?Selection $selection = null;

    /**
     * Upstream attribute types required before normalizing the root resource.
     *
     * @var array<string, string>
     */
    private array $required = [];

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
     * Connect native parsing and defer model conventions until the controller is ready.
     *
     * @param list<string> $except Actions excluded from automatic request and response mapping.
     */
    public function using(ControllerContract $contract, array $except = []): self
    {
        $this->config['controllerContract'] = $contract;
        $this->except = [...$this->except, ...array_map(strtolower(...), $except)];
        $contract->configure($this);

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
        $this->except = [...$this->except, ...array_map(strtolower(...), $except)];

        return $this;
    }

    /**
     * Declare structural input differences and native checks on the original payload.
     *
     * @param array<string, string>                 $fields
     * @param array<string, callable(mixed): mixed> $values
     * @param PayloadReader|null                    $reader
     * @param RequestBypass|null                    $bypass
     * @param RouteParameters|null                  $parameters
     * @param PayloadAdapter|null                   $using
     * @param list<string>                          $nativeOn
     * @param array<string, string>                 $required
     * @param list<string>                          $methods
     * @param list<string>                          $actions
     * @param RequestCheck|null                     $before
     * @param list<string>                          $relations      Adapted native relations.
     * @param array<string, string>                 $lists
     * @param array<string, mixed>                  $defaults
     * @param array<string, string>                 $nativeContains
     */
    public function request(
        array $fields = [], array $values = [], ?callable $reader = null,
        ?callable $bypass = null, ?callable $parameters = null, ?callable $using = null,
        array $nativeOn = [], array $required = [], array $methods = [], array $actions = [],
        ?callable $before = null, array $relations = [], array $lists = [], array $defaults = [],
        array $nativeContains = [],
    ): self {
        $actionNames = array_map(strtolower(...), $actions);
        $nativeMethods = array_map(strtoupper(...), $methods);
        if (array_filter([$nativeOn, $required, $nativeMethods, $nativeContains]) !== []) {
            $this->requestGuards[] = new NativeRequestGuard(
                $nativeOn, $required, $nativeMethods, $actionNames, $nativeContains,
            );
        }
        foreach ($fields as $source => $target) {
            self::validateFieldMap([$source => $target]);
        }
        self::validateFormatters($values);
        $this->config['requestRelations'] = NativeRequestGuard::adaptRelations(
            $this->config['requestRelations'] ?? [], $relations, $actionNames,
        );
        $this->requestRules = Convention::requestRules(
            $this->requestRules, $lists, $defaults, $actionNames,
        );
        $this->requestFields = array_replace($this->requestFields, $fields);
        $this->requestValues = array_replace($this->requestValues, $values);

        $this->requestCallbacks($reader, $bypass, $parameters, $using, $before, $actionNames);

        return $this;
    }

    /**
     * Extend output naming, values and the current response graph in registration order.
     *
     * @param array<string, string>                               $fields      Legacy names.
     * @param array<string, callable(mixed): mixed>               $values
     * @param (callable(JsonResource): JsonResource)|null         $using
     * @param list<string>                                        $except
     * @param list<string>                                        $optional
     * @param AttributeMapper|null                                $attributes
     * @param Finalizer|null                                      $after
     * @param Projection|'*'|null                                 $projection
     * @param array<string, Relation>                             $relations
     * @param array<string, string>                               $required
     * @param list<string>|null                                   $empty
     * @param array<string, array{list<string|int>, string, int}> $rejectEmpty
     */
    public function response(
        array $fields = [], array $values = [], ?callable $using = null,
        array $except = [], array $optional = [], ?callable $attributes = null,
        ?callable $after = null, callable|string|null $projection = null, array $relations = [],
        array $required = [], ?array $empty = null, array $rejectEmpty = [],
    ): self {
        $this->required = array_replace($this->required, $required);
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
            $this->projection = $projection === '*' ? static fn (): null => null : $projection(...);
        }
        if ($using !== null) {
            $this->responseConfigurators[] = $using(...);
        }
        if ($empty !== null || $rejectEmpty !== []) {
            $this->responseConfigurators[] = JsonResource::outcomes($empty, $rejectEmpty);
        }

        return $this;
    }

    /**
     * Translate query differences and keep upstream includes aligned with response selection.
     *
     * @param array<string, string>                 $filters
     * @param array<string, string>                 $sorts
     * @param array<string, string>|null            $includes
     * @param QueryAdapter|null                     $using
     * @param array<string, callable(mixed): mixed> $values
     * @param RelationDefaults|null                 $defaults
     * @param list<string>                          $replaceFor
     * @param list<string>                          $emptyFor
     * @param array<string, list<string>>           $extendFor
     * @param list<string>                          $mergeFor
     * @param list<string>                          $nativeOn   Native search filters.
     */
    public function query(
        array $filters = [], array $sorts = [], ?array $includes = null, ?callable $using = null,
        array $values = [], array|callable|null $defaults = null,
        array $replaceFor = [], array $emptyFor = [], array $extendFor = [],
        array $mergeFor = ['view'], array $nativeOn = [],
    ): self {
        $this->selection = Selection::make(
            $defaults, $replaceFor, $emptyFor, $extendFor, $mergeFor, $this->selection,
        ) ?? $this->selection;
        foreach ([$filters, $sorts] as $aliases) {
            foreach ($aliases as $field => $name) {
                if ($field === '' || $name === '') {
                    throw new InvalidArgumentException('Query aliases require nonempty names.');
                }
            }
        }
        self::validateFormatters($values);
        if ($nativeOn !== []) {
            $this->config['nativeQueryFilters'] = $nativeOn;
        }
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
     * @param Body|null                          $body
     */
    public function get(
        string $action, string $path, array $fromBody = [], ?array $body = null,
    ): self {
        return $this->route($action, 'GET', $path, $fromBody, $body);
    }

    /**
     * Map a legacy action to an upstream POST route.
     *
     * @param array<string, string|list<string>> $fromBody
     * @param Body|null                          $body
     */
    public function post(
        string $action, string $path, array $fromBody = [], ?array $body = null,
    ): self {
        return $this->route($action, 'POST', $path, $fromBody, $body);
    }

    /**
     * Map a legacy action to an upstream PUT route.
     *
     * @param array<string, string|list<string>> $fromBody
     * @param Body|null                          $body
     */
    public function put(
        string $action, string $path, array $fromBody = [], ?array $body = null,
    ): self {
        return $this->route($action, 'PUT', $path, $fromBody, $body);
    }

    /**
     * Map a legacy action to an upstream DELETE route.
     *
     * @param array<string, string|list<string>> $fromBody
     * @param Body|null                          $body
     */
    public function delete(
        string $action, string $path, array $fromBody = [], ?array $body = null,
    ): self {
        return $this->route($action, 'DELETE', $path, $fromBody, $body);
    }

    /**
     * Export the Yii filter definition with its routes and bypass fields.
     *
     * @return array{0: class-string<StranglerFilter>, config: Config}
     */
    public function build(): array
    {
        $contract = $this->config['controllerContract'] ?? null;
        if ($contract !== null && $this->identifier === null && $contract->identifier() === null) {
            $snapshot = clone $this;
            $config = array_intersect_key(
                $this->config, array_fill_keys(['feature', 'bypass', 'nativeQueryFilters'], true),
            )
                + ['resolve' => static fn (CController $controller): array => $snapshot->bound(
                    $controller, $contract,
                )];
        } else {
            $config = $this->configuration();
        }

        return [StranglerFilter::class, 'config' => ['routes' => $this->routes] + $config];
    }

    /**
     * Compile a request-local declaration after the controller's preceding Yii filters.
     *
     * @return BoundConfig
     */
    private function bound(CController $controller, ControllerContract $contract): array
    {
        $identifier = ControllerContract::modelKey($contract->model($controller));
        $bound = clone $this;
        $bound->identifier = $identifier;

        return ['routes' => $bound->routes] + $bound->configuration();
    }

    /**
     * Attach native hooks to the compiled mapping configuration without reading a model.
     *
     * @return BoundConfig
     */
    private function configuration(): array
    {
        $config = $this->compiled();
        if ($this->requestChecks !== []) {
            $checks = $this->requestChecks;
            $config['beforeRequest'] = static function (
                array $payload, string $action, CController $controller,
            ) use ($checks): void {
                $snapshot = new InlineMapping(
                    [], ['action' => $action, 'query' => [], 'payload' => $payload],
                );
                foreach ($checks as $entry) {
                    if ($entry['actions'] === [] || in_array($action, $entry['actions'], true)) {
                        ($entry['check'])(
                            $snapshot->context()['payload'] ?? [], $action, $controller,
                        );
                    }
                }
            };
        }
        if ($this->requestGuards !== []) {
            $config['requestGuards'] = $this->requestGuards;
        }
        $contract = $config['controllerContract'] ?? null;
        if ($contract !== null) {
            $mappings = $config['mappings'] ?? [];
            $mappings['errors'] = ($mappings['errors'] ?? []) + ['*' => $contract->error(...)];
            $config['mappings'] = $mappings;
        }
        if (isset($config['nativeParent']) && ! isset($config['controllerContract'])) {
            throw new InvalidArgumentException('within() requires a controller contract.');
        }

        return $config;
    }

    /**
     * Compile convention defaults after all routes and explicit callbacks are known.
     *
     * @return BoundConfig
     */
    private function compiled(): array
    {
        if (! $this->isConventionEnabled && ! isset($this->config['controllerContract'])) {
            $fieldSettings = [
                $this->responseFields, $this->requestFields, $this->requestValues,
                $this->responseValues, $this->filterAliases, $this->sortAliases,
                $this->responseConfigurators, $this->relations, $this->excludedAttributes,
                $this->optionalAttributes, $this->attributeMappers, $this->recordFinalizers,
                $this->includes !== null, $this->queryAdapter !== null, $this->projection !== null,
                $this->payloadAdapter !== null, $this->queryValues,
                $this->selection !== null, $this->required, $this->requestRules,
            ];
            if (array_filter($fieldSettings) !== []) {
                throw new InvalidArgumentException('Field mappings require a native identifier.');
            }

            return $this->config;
        }

        $identifier = $this->nativeIdentifier();
        $record = $this->record($identifier, $this->config['controllerContract'] ?? null);
        $convention = $this->convention($identifier, $record);
        $actions = array_values(array_diff(array_keys($this->routes), $this->except));
        $mappings = $this->config['mappings'] ?? [];
        $mappings['query'] = self::defaults(
            $mappings['query'] ?? [], $this->queryMapper($convention), $actions,
        );
        $payloadAdapter = $this->payloadAdapter;
        $payloadMapper = $payloadAdapter === null
            ? $convention : $convention->adapt($payloadAdapter);
        $mappings['payload'] = self::defaults($mappings['payload'] ?? [], $payloadMapper, $actions);
        $mappings['response'] = self::defaults(
            $mappings['response'] ?? [], $convention->response(...), $actions,
        );

        return array_replace($this->config, [
            'bodyIdentifier' => $identifier,
            'mappings'       => $mappings + ['responseFields' => $record->schema()->names()],
            'responseRecord' => $record,
        ]);
    }

    /**
     * Bind parsing, native checks and semantic adapters without inspecting a live request.
     *
     * @param PayloadReader|null   $reader
     * @param RequestBypass|null   $bypass
     * @param RouteParameters|null $parameters
     * @param PayloadAdapter|null  $using
     * @param RequestCheck|null    $before
     * @param list<string>         $actions
     */
    private function requestCallbacks(
        ?callable $reader, ?callable $bypass, ?callable $parameters,
        ?callable $using, ?callable $before, array $actions,
    ): void {
        if ($before !== null) {
            $this->requestChecks[] = ['actions' => $actions, 'check' => $before];
        }
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
    }

    /**
     * Compile field aliases, application query semantics and root relation selection once.
     */
    private function queryMapper(Convention $convention): Query
    {
        $query = $convention->queryMapper()->values($this->queryValues);
        if ($this->includes !== null) {
            $query = $query->includes($this->includes);
        }
        $adapter = $this->queryAdapter;
        $query = $adapter === null ? $query : $query->adapt($adapter);
        if ($this->selection !== null) {
            $query = $query->adapt($this->selection->query(...));
        }

        return $query;
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
                'Conventional mapping requires a native identifier.',
            );
        }

        return $identifier;
    }

    /**
     * Bind application extension points once, leaving mapping execution stateless.
     */
    private function convention(string $identifier, Record $record): Convention
    {
        $contract = $this->config['controllerContract'] ?? null;
        $resultUsing = null;
        if ($contract !== null) {
            $resultUsing = $contract->responseResult(...);
        }

        return new Convention(
            identifier: $identifier,
            output: $this->resource($record, $contract),
            requestFields: $this->requestFields,
            requestValues: $this->requestValues,
            filterAliases: $this->filterAliases,
            sortAliases: $this->sortAliases,
            resultUsing: $resultUsing,
            rules: $this->requestRules,
        );
    }

    /**
     * Compile one response graph and layer explicit resource differences over contract defaults.
     */
    private function record(string $identifier, ?ControllerContract $contract): Record
    {
        $fieldsUsing = $contract === null ? null : $contract->responseFields(...);
        $graph = new ResponseGraph(
            prototype: Record::uppercase()->rename($this->responseFields)
                ->required($this->required)
                ->except($this->excludedAttributes)
                ->optional($this->optionalAttributes),
            values: $this->responseValues,
            relations: array_replace($contract?->relations() ?? [], $this->relations),
            attributes: $contract === null ? [] : [$contract->responseAttributes(...)],
            after: $contract === null ? [] : [$contract->responseAfter(...)],
            fieldsUsing: $fieldsUsing,
            controllerUsing: ControllerContract::responseRecord(...),
            rootCallbacks: [
                'attributes' => $this->attributeMappers,
                'after'      => $this->recordFinalizers,
            ],
        );

        return $graph->record($identifier);
    }

    /**
     * Apply root request selection and envelope settings to an immutable record schema.
     */
    private function resource(Record $record, ?ControllerContract $contract): JsonResource
    {
        $projection = $this->projection;
        if ($projection === null && $this->selection !== null) {
            $projection = $this->selection->projection(...);
        }
        if ($projection === null && $contract !== null) {
            $projection = $contract->responseProjection(...);
        }
        $resource = JsonResource::make()->recordUsing($record);
        if ($projection !== null) {
            $resource = $resource->projectionUsing($projection);
        }
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
     * @param Body|null                          $body
     */
    public function route(
        string $action, string $method, string $path, array $fromBody = [], ?array $body = null,
    ): self {
        $method = strtoupper($method);
        $key = $this->routeKey($action, $method, $path);

        BodyParameters::validate($fromBody, $path);
        $route = ['method' => $method, 'path' => $path];
        if ($fromBody !== []) {
            $route['fromBody'] = $fromBody;
        }
        if ($body !== null) {
            $this->mapPayload(BodyParameters::mapper($body), [$key]);
        }
        $this->routes[$key] = $route;

        return $this;
    }

    /**
     * Validate one action and its local HTTP destination before registering any mappings.
     */
    private function routeKey(string $action, string $method, string $path): string
    {
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

        return $key;
    }
}
