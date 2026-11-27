<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Internal\Http\ExpectedStatus;
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
 * @phpstan-import-type Modifier from Query as QueryModifier
 * @phpstan-import-type BodyModifier from JsonResource
 * @phpstan-import-type Relation from ResponseGraph
 * @phpstan-import-type AttributeMapper from ResponseGraph
 * @phpstan-import-type Finalizer from ResponseGraph
 * @phpstan-import-type Projection from ResponseGraph
 * @phpstan-import-type Context from InlineMapping
 * @phpstan-import-type Settings from InlineMapping
 * @phpstan-import-type QueryMapper from InlineMapping
 * @phpstan-import-type CustomPayloadMapper from InlineMapping
 * @phpstan-import-type ResponseMapper from InlineMapping
 * @phpstan-import-type Fallback from RouteOptions
 *
 * @phpstan-type QueryAdapter callable(
 *     array<array-key, mixed>, array{action: string}, Query
 * ): (array<array-key, mixed>|false)
 * @phpstan-type PayloadReader callable(CController, string): array<array-key, mixed>
 * @phpstan-type RequestBypass callable(
 *     array<array-key, mixed>, string, array<array-key, mixed>
 * ): bool
 * @phpstan-type RouteParameters callable(Context): (array<string, mixed>|false)
 * @phpstan-type RouteResolver callable(
 *     array<array-key, mixed>, array<array-key, mixed>
 * ): (array<string, mixed>|false)
 * @phpstan-type RouteRequest callable(array<array-key, mixed>): (array<array-key, mixed>|false)
 *
 * @phpstan-import-type RouteBody from InlineMapping
 *
 * @phpstan-type PayloadAdapter callable(
 *     array<array-key, mixed>, array{action: string}, Convention
 * ): (array<array-key, mixed>|\stdClass|false)
 *
 * @phpstan-import-type Body from BodyParameters
 * @phpstan-import-type RequestCheck from InlineMapping
 *
 * @phpstan-type RelationDefaults array<string, mixed>|callable(
 *     array<array-key, mixed>
 * ): ?array<string, mixed>
 * @phpstan-type ProjectionClosure Closure(
 *     array{action: string, query?: array<array-key, mixed>}
 * ): ?array<string, mixed>
 * @phpstan-type ActionHandler callable(
 *     StranglerProxy, array<array-key, mixed>, array<array-key, mixed>
 * ): \Generator<array-key, \Psr\Http\Message\ResponseInterface, mixed, void>
 * @phpstan-type Action array{incoming: string, run: ActionHandler, checkNative?: true}
 * @phpstan-type Selector callable(
 *     self, array<array-key, mixed>, array<array-key, mixed>
 * ): ?self
 * @phpstan-type Choice array{
 *     incoming: string, choose: callable(array<array-key, mixed>, array<array-key, mixed>): ?self
 * }
 * @phpstan-type Route array{
 *     method: string, path: string, incoming?: string,
 *     fromBody?: array<string, string|list<string>>, parameters?: RouteResolver,
 *     request?: RouteRequest, expects?: int, fallback?: Fallback
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
 * @phpstan-type Config array{
 *     actions?: array<string, Action>, choices?: array<string, Choice>,
 *     actionOptions?: BoundConfig,
 *     resolve?: callable(CController): BoundConfig,
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
 */
final class Strangler
{
    /**
     * Methods accepted in route declarations on either side of the proxy.
     */
    private const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Collection actions mapped to their upstream page sizes.
     *
     * @var array<string, int>
     */
    private array $completeCollections = [];

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
     * Bind payload predicates to normalized Yii action names.
     *
     * @var array<string, RequestBypass>
     */
    private array $scopedBypasses = [];

    /**
     * Upstream routes indexed by normalized legacy action names.
     *
     * @var array<string, Route>
     */
    private array $routes = [];

    /**
     * Application-defined requests indexed by the same Yii action names as routes.
     *
     * @var array<string, Action>
     */
    private array $actions = [];

    /**
     * Defer each selection until the original Yii form is available.
     *
     * @var array<string, array{string, Selector}>
     */
    private array $choices = [];

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
     * Routes that preserve success bytes while retaining request and error mappings.
     *
     * @var list<string>
     */
    private array $binaryActions = [];
    /**
     * Response-only naming exceptions.
     *
     * @var array<string, string>
     */
    private array $responseFields = [];

    /**
     * Naming exceptions shared by writes, responses, queries and validation errors.
     *
     * @var array<string, string>
     */
    private array $fields = [];
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
     * Structural query changes that retain automatic field and pagination mapping.
     *
     * @var (Closure(
     *     array<array-key, mixed>, array{action: string}
     * ): (array<array-key, mixed>|false))|null
     */
    private ?Closure $queryModifier = null;

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
        $proxy = new self($feature);
        $contract = ControllerContract::fromApplication();

        return $contract === null ? $proxy : $proxy->using($contract);
    }

    /**
     * Connect native parsing and defer model conventions until the controller is ready.
     *
     * @param list<string> $except Actions excluded from automatic request and response mapping.
     */
    public function using(ControllerContract $contract, array $except = []): self
    {
        $this->except = [...$this->except, ...array_map(strtolower(...), $except)];
        $this->config['controllerContract'] = $contract;
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
     * Describe a model-free JSON object shared by this group's writes and successful reads.
     *
     * @param array<string, string> $fields
     */
    public function jsonFields(array $fields): self
    {
        $this->config['mappings']['jsonFields'] = new JsonFields($fields);

        return $this;
    }

    /**
     * Declare common naming differences once for all mapping directions.
     *
     * @param array<string, string> $fields
     */
    public function fields(array $fields): self
    {
        $names = array_replace($this->fields, $fields);
        self::validateFieldMap($names);
        $this->fields = $names;

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
        Convention::validateFormatters($values);
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
     * @param BodyModifier|null                                   $body
     * @param list<string>|null                                   $empty
     * @param array<string, array{list<string|int>, string, int}> $rejectEmpty
     */
    public function response(
        array $fields = [], array $values = [], ?callable $using = null,
        array $except = [], array $optional = [], ?callable $attributes = null,
        ?callable $after = null, callable|string|null $projection = null, array $relations = [],
        array $required = [], ?array $empty = null, array $rejectEmpty = [],
        ?callable $body = null,
    ): self {
        $this->required = array_replace($this->required, $required);
        self::validateFieldMap($fields);
        Convention::validateFormatters($values);
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
        foreach (array_filter([
            $using, JsonResource::outcomes($empty, $rejectEmpty, $body),
        ]) as $configure) {
            $this->responseConfigurators[] = $configure(...);
        }

        return $this;
    }

    /**
     * Translate query differences and keep upstream includes aligned with response selection.
     *
     * @param array<string, string>                 $filters
     * @param array<string, string>                 $sorts
     * @param array<string, string>|null            $includes
     * @param QueryModifier|null                    $modify
     * @param QueryAdapter|null                     $using
     * @param array<string, callable(mixed): mixed> $values
     * @param RelationDefaults|null                 $defaults
     * @param list<string>                          $replaceFor
     * @param list<string>                          $emptyFor
     * @param array<string, list<string>>           $extendFor
     * @param list<string>                          $mergeFor
     * @param list<string>                          $nativeOn   Native search filters.
     * @param array<string, int>                    $complete   Actions and collection page sizes.
     */
    public function query(
        array $filters = [], array $sorts = [], ?array $includes = null, ?callable $using = null,
        array $values = [], array|callable|null $defaults = null,
        array $replaceFor = [], array $emptyFor = [], array $extendFor = [],
        array $mergeFor = ['view'], array $nativeOn = [], array $complete = [],
        ?callable $modify = null,
    ): self {
        $this->selection = Selection::make(
            $defaults, $replaceFor, $emptyFor, $extendFor, $mergeFor, $this->selection,
        ) ?? $this->selection;
        $names = [...array_keys($filters), ...$filters, ...array_keys($sorts), ...$sorts];
        if (in_array('', $names, true)) {
            throw new InvalidArgumentException('Query aliases require nonempty names.');
        }
        Convention::validateFormatters($values);
        $this->completeCollections = Query::collectionPages($complete) + $this->completeCollections;
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
        if ($modify !== null) {
            $this->queryModifier = $modify(...);
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
        $mappings['query'] = InlineMapping::register($mappings['query'] ?? [], $mapper, $actions);
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
        $mappings['payload'] = InlineMapping::register(
            $mappings['payload'] ?? [], $mapper, $actions,
        );
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
        $mappings['response'] = InlineMapping::register(
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
        $mappings['errors'] = InlineMapping::register($mappings['errors'] ?? [], $mapper, $actions);
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
        $this->config['files'] = InlineMapping::register(
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
     * @param array<string, string|list<string>>   $fromBody
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function get(
        string $action, string $path, array $fromBody = [], array|callable|null $body = null,
        ?callable $parameters = null, bool $binary = false, ?int $expects = null,
        ?string $incoming = null, ?callable $configure = null,
    ): self {
        $this->route(
            $action, 'GET', $path, $fromBody, $body, $parameters,
            expects: $expects, incoming: $incoming, configure: $configure,
        );
        if ($binary) {
            $this->binaryActions[] = strtolower($action);
        }

        return $this;
    }

    /**
     * Map a legacy action to an upstream POST route.
     *
     * @param array<string, string|list<string>>   $fromBody
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function post(
        string $action, string $path, array $fromBody = [], array|callable|null $body = null,
        ?callable $parameters = null, ?int $expects = null, ?string $incoming = null,
        ?callable $configure = null,
    ): self {
        return $this->route(
            $action, 'POST', $path, $fromBody, $body, $parameters,
            expects: $expects, incoming: $incoming, configure: $configure,
        );
    }

    /**
     * Map a legacy action to an upstream PUT route.
     *
     * @param array<string, string|list<string>>   $fromBody
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function put(
        string $action, string $path, array $fromBody = [], array|callable|null $body = null,
        ?callable $parameters = null, ?int $expects = null, ?string $incoming = null,
        ?callable $configure = null,
    ): self {
        return $this->route(
            $action, 'PUT', $path, $fromBody, $body, $parameters,
            expects: $expects, incoming: $incoming, configure: $configure,
        );
    }

    /**
     * Forward a partial update with its request and response adaptations.
     *
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param RouteRequest|null                    $request
     * @param ResponseMapper|null                  $response
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function patch(
        string $action, string $path, array|callable|null $body = null,
        ?callable $parameters = null, ?callable $request = null, ?callable $response = null,
        ?int $expects = null, ?string $incoming = null, ?callable $configure = null,
    ): self {
        return $this->route(
            $action, 'PATCH', $path, body: $body, parameters: $parameters,
            request: $request, response: $response, expects: $expects, incoming: $incoming,
            configure: $configure,
        );
    }

    /**
     * Map a legacy action to an upstream DELETE route.
     *
     * @param array<string, string|list<string>>   $fromBody
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function delete(
        string $action, string $path, array $fromBody = [], array|callable|null $body = null,
        ?callable $parameters = null, ?int $expects = null, ?string $incoming = null,
        ?callable $configure = null,
    ): self {
        return $this->route(
            $action, 'DELETE', $path, $fromBody, $body, $parameters,
            expects: $expects, incoming: $incoming, configure: $configure,
        );
    }

    /**
     * Yield each upstream response before issuing the next request for this Yii action.
     *
     * @param ActionHandler $requests
     */
    public function action(
        string $action, string $incoming, callable $requests, bool $checkNative = false,
    ): self {
        $key = $this->actionKey($action);
        $run = $requests(...);
        if (! (new \ReflectionFunction($run))->isGenerator()) {
            throw new InvalidArgumentException(
                'Strangler actions must yield each upstream response.',
            );
        }
        $this->actions[$key] = ['incoming' => self::incomingMethod($incoming), 'run' => $run]
            + ($checkNative ? ['checkNative' => true] : []);

        return $this;
    }

    /**
     * Choose one route or custom action for the native form. Return null to continue in Yii.
     *
     * @param Selector $select
     */
    public function choose(string $action, string $incoming, callable $select): self
    {
        $key = $this->actionKey($action);
        $this->choices[$key] = [self::incomingMethod($incoming), $select];

        return $this;
    }

    /**
     * Export the Yii filter definition with its routes and bypass fields.
     *
     * @return array{0: class-string<StranglerFilter>, config: Config}
     */
    public function build(): array
    {
        $routes = $this->routes;
        if (isset($this->config['mappings']['jsonFields'])) {
            foreach ($routes as $action => $route) {
                $routes[$action]['incoming'] ??= $route['method'];
            }
        }
        $config = $this->routeConfiguration();
        $config['routes'] = $routes;
        if ($this->actions !== []) {
            $config['actions'] = $this->actions;
        }
        if ($this->choices !== []) {
            $config['choices'] = $this->compiledChoices();
        }
        if ($this->actions !== [] || $this->choices !== []) {
            $config['actionOptions'] = $this->actionOptions();
        }

        return [StranglerFilter::class, 'config' => $config];
    }

    /**
     * Validate opt-in model guards before dispatch, without loading an Active Record.
     *
     * @return BoundConfig
     */
    private function actionOptions(): array
    {
        $action = array_key_first(array_filter(
            $this->actions, static fn (array $route): bool => isset($route['checkNative']),
        ));
        if ($action === null) {
            return $this->withHooks($this->config);
        }
        if (ControllerContract::forModel($this->config) === null) {
            throw new InvalidArgumentException(
                "Action {$action} uses checkNative. "
                .'Register a model-based controller contract with using().',
            );
        }

        $identifier = $this->identifier === null ? [] : ['bodyIdentifier' => $this->identifier];

        return $this->withHooks($identifier + $this->config);
    }

    /**
     * Keep the final shared mappings, then give each request its own empty route table.
     *
     * @return array<string, Choice>
     */
    private function compiledChoices(): array
    {
        $defaults = clone $this;
        $defaults->actions = [];
        $defaults->routes = [];
        $defaults->choices = [];
        $bypass = $defaults->payloadBypass();
        $defaults->scopedBypasses = [];
        unset(
            $defaults->config['bypass'],
            $defaults->config['nativeQueryFilters'],
            $defaults->config['bypassPayload'],
        );

        $choices = [];
        foreach ($this->choices as $action => [$incoming, $select]) {
            $choices[$action] = [
                'incoming' => $incoming,
                'choose'   => static function (array $form, array $query) use (
                    $defaults, $select, $action, $incoming, $bypass,
                ): ?self {
                    if ($bypass !== null && $bypass($form, $action, $query)) {
                        return null;
                    }
                    $branch = clone $defaults;
                    $selected = $select(
                        $branch, InlineMapping::copy($form), InlineMapping::copy($query),
                    );
                    if ($selected === null) {
                        return null;
                    }
                    if ($selected !== $branch || $branch->choices !== []
                        || array_keys($branch->routes + $branch->actions) !== [$action]) {
                        throw new InvalidArgumentException(
                            "Choose one route or action for {$action} on the supplied builder.",
                        );
                    }
                    if (isset($branch->routes[$action])) {
                        $branch->routes[$action]['incoming'] ??= $incoming;
                    }

                    return $branch;
                },
            ];
        }

        return $choices;
    }

    /**
     * Delay record conventions until a routed request provides its native controller.
     *
     * @return Config
     */
    private function routeConfiguration(): array
    {
        if ($this->routes === [] && ($this->actions !== [] || $this->choices !== [])) {
            return $this->config;
        }
        $contract = ControllerContract::forModel($this->config);
        if ($contract === null || $this->identifier !== null || $contract->identifier() !== null) {
            return $this->withHooks($this->compiled());
        }
        $snapshot = clone $this;

        return array_intersect_key(
            $this->config, array_fill_keys(['feature', 'bypass', 'nativeQueryFilters'], true),
        ) + ['resolve' => static fn (CController $controller): array => $snapshot->bound(
            $controller, $contract,
        )];
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

        return ['routes' => $bound->routes] + $bound->withHooks($bound->compiled());
    }

    /**
     * Attach native hooks to the compiled mapping configuration without reading a model.
     *
     * @param BoundConfig $config
     *
     * @return BoundConfig
     */
    private function withHooks(array $config): array
    {
        $bypass = $this->payloadBypass();
        if ($bypass !== null) {
            $config['bypassPayload'] = $bypass;
        }
        if ($this->requestChecks !== []) {
            $config['beforeRequest'] = InlineMapping::beforeRequest($this->requestChecks);
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
        if (isset($config['nativeParent']) && ControllerContract::forModel($config) === null) {
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
        $mappings = array_filter(['completeCollections' => $this->completeCollections])
            + ($this->config['mappings'] ?? []);
        $jsonFields = $mappings['jsonFields'] ?? null;
        if ($jsonFields !== null) {
            $reads = array_keys(array_filter(
                $this->routes, static fn (array $route): bool => $route['method'] !== 'HEAD',
            ));
            $mappings['response'] = InlineMapping::defaults(
                $mappings['response'] ?? [], $jsonFields->response(...),
                array_values(array_diff($reads, $this->binaryActions)),
            );

            return array_replace($this->config, ['mappings' => $mappings]);
        }
        if (! $this->isConventionEnabled && ! isset($this->config['controllerContract'])) {
            $fieldSettings = [
                $this->fields, $this->responseFields, $this->requestFields, $this->requestValues,
                $this->responseValues, $this->filterAliases, $this->sortAliases,
                $this->responseConfigurators, $this->relations, $this->excludedAttributes,
                $this->optionalAttributes, $this->attributeMappers, $this->recordFinalizers,
                $this->includes !== null, $this->queryAdapter !== null, $this->projection !== null,
                $this->payloadAdapter !== null, $this->queryValues,
                $this->selection !== null, $this->required,
                $this->requestRules, $this->queryModifier,
            ];
            if (array_filter($fieldSettings) !== []) {
                throw new InvalidArgumentException('Field mappings require a native identifier.');
            }

            return array_replace($this->config, array_filter(['mappings' => $mappings]));
        }

        $identifier = $this->nativeIdentifier();
        $record = $this->record($identifier, $this->config['controllerContract'] ?? null);
        $convention = $this->convention($identifier, $record);
        $actions = array_values(array_diff(array_keys($this->routes), $this->except));
        $mappings['query'] = InlineMapping::defaults(
            $mappings['query'] ?? [], $this->queryMapper($convention), $actions,
        );
        $payloadAdapter = $this->payloadAdapter;
        $payloadMapper = $payloadAdapter === null
            ? $convention : $convention->adapt($payloadAdapter);
        $mappings['payload'] = InlineMapping::defaults(
            $mappings['payload'] ?? [], $payloadMapper, $actions,
        );
        $mappings['response'] = InlineMapping::defaults(
            $mappings['response'] ?? [], $convention->response(...),
            array_values(array_diff($actions, $this->binaryActions)),
        );

        $mappings['errorFields'] = array_replace(
            $record->schema()->names(), $this->fields, array_flip($this->requestFields),
        );

        return array_replace($this->config, [
            'bodyIdentifier' => $identifier,
            'mappings'       => $mappings + [
                'responseFields' => $record->schema()->names(), 'convention' => $payloadMapper,
            ],
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
            if ($actions === []) {
                $this->config['bypassPayload'] = $bypass;
            } else {
                foreach ($actions as $action) {
                    $this->scopedBypasses[$action] = $bypass;
                }
            }
        }
        if ($parameters !== null) {
            $this->config['routeParameters'] = $parameters;
        }

        if ($using !== null) {
            $this->payloadAdapter = $using(...);
        }
    }

    /**
     * Apply shared and action-specific predicates to the same original form.
     *
     * @return RequestBypass|null
     */
    private function payloadBypass(): ?callable
    {
        $global = $this->config['bypassPayload'] ?? null;
        $scoped = $this->scopedBypasses;
        if ($scoped === []) {
            return $global;
        }

        return static function (
            array $payload, string $action, array $query,
        ) use ($global, $scoped): bool {
            $predicate = $scoped[$action] ?? null;

            return ($global !== null && $global($payload, $action, $query))
                || ($predicate !== null && $predicate($payload, $action, $query));
        };
    }

    /**
     * Compile field aliases, application query semantics and root relation selection once.
     */
    private function queryMapper(Convention $convention): Query
    {
        $query = $convention->queryMapper($this->filterAliases, $this->sortAliases)
            ->values($this->queryValues);
        if ($this->includes !== null) {
            $query = $query->includes($this->includes);
        }
        $adapter = $this->queryAdapter;
        $query = $adapter === null ? $query : $query->adapt($adapter);
        if ($this->queryModifier !== null) {
            $query = $query->modify($this->queryModifier);
        }
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

        return new Convention(
            identifier: $identifier,
            output: $this->resource($record, $contract),
            requestFields: $this->requestFields,
            fields: $this->fields,
            requestValues: $this->requestValues,
            resultUsing: $contract === null ? null : [$contract, 'responseResult'],
            rules: $this->requestRules,
        );
    }

    /**
     * Compile one response graph and layer explicit resource differences over contract defaults.
     */
    private function record(string $identifier, ?ControllerContract $contract): Record
    {
        $graph = new ResponseGraph(
            prototype: Record::uppercase()
                ->rename(array_replace($this->fields, $this->responseFields))
                ->required($this->required)
                ->except($this->excludedAttributes)
                ->optional($this->optionalAttributes),
            values: $this->responseValues,
            relations: array_replace($contract?->relations() ?? [], $this->relations),
            attributes: $contract === null ? [] : [$contract->responseAttributes(...)],
            after: $contract === null ? [] : [$contract->responseAfter(...)],
            fieldsUsing: $contract === null ? null : [$contract, 'responseFields'],
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
        $projection = $this->projection
            ?? ($this->selection === null ? null : [$this->selection, 'projection'])
            ?? ($contract === null ? null : [$contract, 'responseProjection']);
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
     * Register a route using a case-insensitive action key.
     *
     * @param array<string, string|list<string>>   $fromBody
     * @param Body|RouteBody|null                  $body
     * @param RouteResolver|null                   $parameters
     * @param RouteRequest|null                    $request
     * @param ResponseMapper|null                  $response
     * @param (callable(RouteOptions): mixed)|null $configure
     */
    public function route(
        string $action, string $method, string $path, array $fromBody = [],
        array|callable|null $body = null,
        ?callable $parameters = null, ?callable $request = null, ?callable $response = null,
        ?int $expects = null, ?string $incoming = null, ?callable $configure = null,
    ): self {
        $method = strtoupper($method);
        $key = $this->routeKey($action, $method, $path);

        $incomingMethod = $incoming === null ? null : self::incomingMethod($incoming);
        ExpectedStatus::validate($expects);
        BodyParameters::validate($fromBody, $path);
        if ($body !== null) {
            $select = is_callable($body) ? $body : BodyParameters::mapper($body);
            $this->mapPayload(
                static fn (
                    array $payload, array $context, callable $map,
                ) => $map($select($context['payload'] ?? $payload)),
                [$key],
            );
        }
        if ($response !== null) {
            $this->mapJsonResponse($response, [$key]);
        }
        $this->routes[$key] = ['method' => $method, 'path' => $path] + array_filter([
            'fromBody' => $fromBody, 'parameters' => $parameters, 'request' => $request,
            'expects'  => $expects, 'incoming' => $incomingMethod,
        ]);
        if ($configure !== null) {
            $options = new RouteOptions($this, $key);
            $configure($options);
            $fallback = $options->fallback();
            if ($fallback !== null) {
                $this->routes[$key]['fallback'] = $fallback;
            }
        }

        return $this;
    }

    /**
     * Match the request method as reported by Yii before binding the controller.
     */
    private static function incomingMethod(string $method): string
    {
        $method = strtoupper($method);
        if (! in_array($method, self::HTTP_METHODS, true)) {
            throw new InvalidArgumentException('Unsupported incoming HTTP method.');
        }

        return $method;
    }

    /**
     * Validate one action and its local HTTP destination before registering any mappings.
     */
    private function routeKey(string $action, string $method, string $path): string
    {
        if (! in_array($method, self::HTTP_METHODS, true)) {
            throw new InvalidArgumentException('Unsupported upstream HTTP method.');
        }
        $key = $this->actionKey($action);
        if ($path === '') {
            throw new InvalidArgumentException('Strangler routes require an action and path.');
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Strangler routes require one leading slash.');
        }

        return $key;
    }

    /**
     * Reserve each Yii action name across routes, custom actions and form selectors.
     */
    private function actionKey(string $action): string
    {
        if ($action === '') {
            throw new InvalidArgumentException('Strangler routes require an action and path.');
        }
        $key = strtolower($action);
        if (isset($this->routes[$key]) || isset($this->actions[$key])
            || isset($this->choices[$key])) {
            throw new InvalidArgumentException('Duplicate Strangler action: '.$action);
        }

        return $key;
    }
}
