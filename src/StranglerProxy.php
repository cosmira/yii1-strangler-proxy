<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Internal\Payload\BodyParameters;
use Cosmira\Strangler\Internal\Payload\NativeRequestGuard;
use CWebApplication;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * @phpstan-import-type Config from Strangler
 * @phpstan-import-type Route from Strangler
 * @phpstan-import-type Context from InlineMapping
 * @phpstan-import-type RequestValues from InlineMapping
 *
 * @phpstan-type Prepared array{
 *     outgoing: array{method: string, path: string, query: array<array-key, mixed>,
 *         payload: array<array-key, mixed>, body: string|null},
 *     pathQuery: array<array-key, mixed>, routeId: mixed, parameters: array<string, int|string>
 * }
 */
final readonly class StranglerProxy
{
    /**
     * Send upstream attempts through the HTTP transport boundary.
     */
    private UpstreamTransport $transport;

    /**
     * Send responses through the Yii output boundary.
     */
    private ResponseEmitter $emitter;

    /**
     * Supply a client and optional output/clock adapters.
     *
     * @param Closure(): float|null $clock
     */
    public function __construct(
        ?ClientInterface $client = null,
        ?ResponseEmitter $emitter = null,
        ?Closure $clock = null,
    ) {
        $this->emitter = $emitter ?? new ResponseEmitter();
        $this->transport = new UpstreamTransport(
            $client, $this->emitter, $clock ?? static fn (): float => microtime(true),
        );
    }

    /**
     * Check whether the feature is enabled and the action has an upstream route.
     *
     * @param Config $config
     */
    public static function shouldHandle(array $config, string $actionId): bool
    {
        $actionKey = self::normalizeActionKey($actionId);
        if (! YiiRuntime::allowsFeature($config, $actionKey)) {

            return false;
        }

        $routes = $config['routes'] ?? [];
        if (! isset($routes[$actionKey])) {
            YiiRuntime::logDecision($config, $actionKey, 'route_unmapped');

            return false;
        }

        return true;
    }

    /**
     * Check whether a callback or payload field requires legacy handling.
     *
     * @param Config                       $config
     * @param array<array-key, mixed>|null $payload
     */
    public static function shouldBypass(
        CController $controller,
        array $config,
        string $actionId,
        ?array $payload = null,
    ): bool {
        $actionKey = self::normalizeActionKey($actionId);
        if (self::queryIsNative($controller, $config, $actionKey)) {
            return true;
        }

        $bound = ControllerContract::resolveConfig($config, $controller);

        return self::payloadIsNative($controller, $bound, $actionKey, $payload);
    }

    /**
     * Resolve declared native searches and application bypasses before model binding.
     *
     * @param Config $config
     */
    private static function queryIsNative(
        CController $controller, array $config, string $action,
    ): bool {
        if (NativeRequestGuard::hasNativeFilter($config['nativeQueryFilters'] ?? [], $_GET)) {
            return true;
        }
        $bypass = $config['bypass'] ?? null;

        return $bypass !== null && $bypass($controller, $action, $_GET);
    }

    /**
     * Evaluate payload eligibility once against the original native request snapshot.
     *
     * @param Config                       $config
     * @param array<array-key, mixed>|null $payload
     */
    private static function payloadIsNative(
        CController $controller, array $config, string $actionKey, ?array $payload,
    ): bool {
        $predicate = $config['bypassPayload'] ?? null;
        $guards = $config['requestGuards'] ?? [];
        $contract = $config['controllerContract'] ?? null;
        if ($predicate === null && $guards === [] && $contract === null) {
            return false;
        }
        $original = $payload ?? self::readPayload($controller, $config, $actionKey)['payload'];
        if ($predicate !== null && $predicate($original, $actionKey, $_GET)) {
            return true;
        }
        $method = self::application()->getRequest()->getRequestType();
        foreach ($guards as $guard) {
            if ($guard->bypass(
                $original, $actionKey, $_GET, $method, $config['bodyIdentifier'] ?? null,
            )) {
                return true;
            }
        }

        return self::hasNativeRelationWrite($controller, $config, $actionKey, $original);
    }

    /**
     * Keep unadapted nested writes local after consuming declared route parameters.
     *
     * @param Config                  $config
     * @param array<array-key, mixed> $payload
     */
    private static function hasNativeRelationWrite(
        CController $controller, array $config, string $action, array $payload,
    ): bool {
        $contract = $config['controllerContract'] ?? null;
        $relations = in_array($action, ['create', 'edit'], true)
            ? $contract?->nativeRelations($controller) ?? [] : [];
        if ($relations === []) {
            return false;
        }
        $routes = $config['routes'] ?? [];
        $route = $routes[$action] ?? [];
        $binding = BodyParameters::resolve($route['fromBody'] ?? [], $payload);
        if ($binding === false) {
            return true;
        }
        $adapted = $config['requestRelations'] ?? [];
        $native = array_diff(
            array_map(strtolower(...), $relations), $adapted['*'] ?? [], $adapted[$action] ?? [],
        );
        $fields = array_map(strtolower(...), array_keys((array) $binding['payload']));

        return array_intersect($fields, $native) !== [];
    }

    /**
     * Forward a mapped action unless a bypass keeps it on the legacy controller.
     *
     * @param Config $config
     */
    public function handle(CController $controller, array $config, string $actionId): bool
    {
        $actionKey = self::normalizeActionKey($actionId);
        $routes = $config['routes'] ?? [];
        $route = $routes[$actionKey] ?? null;

        if ($route === null) {
            YiiRuntime::logDecision($config, $actionKey, 'route_unmapped');

            return false;
        }

        if (self::queryIsNative($controller, $config, $actionKey)) {
            YiiRuntime::logDecision($config, $actionKey, 'bypass_callback');

            return false;
        }
        $bound = ControllerContract::resolveConfig($config, $controller);
        unset($bound['bypass'], $bound['nativeQueryFilters']);

        $context = [
            'action'        => $actionKey, 'query' => $_GET, 'payload' => null,
            'method'        => strtoupper($route['method']),
            'requestMethod' => self::application()->getRequest()->getRequestType(),
        ];
        if (isset($bound['bodyIdentifier'])) {
            $context['identifier'] = $bound['bodyIdentifier'];
        }
        $inline = isset($bound['mappings'])
            ? new InlineMapping($bound['mappings'], $context) : null;

        return $this->forward($controller, $bound, $actionKey, $route, $inline);
    }

    /**
     * Prepare one payload snapshot and resolve the upstream route before sending.
     *
     * @param Config $config
     * @param Route  $route
     */
    private function forward(
        CController $controller,
        array $config,
        string $actionKey,
        array $route,
        ?InlineMapping $inline,
    ): bool {
        try {
            $snapshot = self::readPayload($controller, $config, $actionKey);
            $payload = $snapshot['payload'];
        } catch (JsonException) {
            $this->emitter->error(400, 'strangler_invalid_payload', $inline);

            return true;
        }

        $contract = $config['controllerContract'] ?? null;
        $nativeBypass = $contract !== null
            && $contract->shouldBypass(
                $controller, $actionKey, $_GET, $payload, $config['bodyIdentifier'] ?? null,
            );
        if ($nativeBypass || self::shouldBypass($controller, $config, $actionKey, $payload)) {
            YiiRuntime::logDecision($config, $actionKey, 'bypass_payload');

            return false;
        }
        if (isset($config['beforeRequest'])) {
            ($config['beforeRequest'])($payload, $actionKey, $controller);
        }
        $mapping = $inline?->withPayload($payload, $snapshot['body']);

        $prepared = self::prepareRequest(
            $controller, $config, $actionKey, $route, $snapshot, $mapping,
        );
        if ($prepared === false) {
            YiiRuntime::logDecision($config, $actionKey, 'mapping_unsupported');

            return false;
        }
        $this->sendPrepared($config, $actionKey, $route['path'], $prepared, $mapping);

        return true;
    }

    /**
     * Resolve route parameters and execute one prepared upstream attempt.
     *
     * @param Config   $config
     * @param Prepared $prepared
     */
    private function sendPrepared(
        array $config,
        string $actionKey,
        string $routeTemplate,
        array $prepared,
        ?InlineMapping $inline,
    ): void {
        $outgoing = $prepared['outgoing'];

        try {
            $outgoing['path'] = self::resolvePath(
                $routeTemplate, $prepared['pathQuery'],
                $outgoing['payload'], $prepared['routeId'], $prepared['parameters'],
            );
        } catch (InvalidArgumentException) {
            YiiRuntime::logRejection(
                $config, $actionKey, $routeTemplate, 'strangler_route_parameter_missing',
            );
            $this->emitter->error(
                400, 'strangler_route_parameter_missing', $inline,
            );

            return;
        }
        $this->transport->send($config, $actionKey, $outgoing, $routeTemplate, $inline);
    }

    /**
     * Normalize legacy action names for route lookup.
     */
    private static function normalizeActionKey(string $actionId): string
    {
        return strtolower($actionId);
    }

    /**
     * Read one payload snapshot while preserving the original JSON body.
     *
     * @param Config $config
     *
     * @return array{payload: array<array-key, mixed>, body: string|null}
     */
    private static function readPayload(
        CController $controller,
        array $config,
        string $actionKey,
    ): array {
        $reader = $config['payloadReader'] ?? null;
        if ($reader === null && isset($config['controllerContract'])) {
            return [
                'payload' => $config['controllerContract']->payload($controller),
                'body'    => null,
            ];
        }

        if ($reader !== null) {
            return ['payload' => $reader($controller, $actionKey), 'body' => null];
        }

        if (YiiRuntime::fileMappings($config, $actionKey) !== null) {
            return ['payload' => $_POST, 'body' => null];
        }

        $body = self::application()->getRequest()->getRawBody();
        if (trim($body) === '') {
            return ['payload' => [], 'body' => $body];
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($decoded)) {
            throw new JsonException('Strangler requires a JSON object or array.');
        }

        return ['payload' => $decoded, 'body' => $body];
    }

    /**
     * Substitute encoded request identifiers into a local upstream path.
     *
     * @param array<array-key, mixed>   $query
     * @param array<array-key, mixed>   $body
     * @param array<string, int|string> $parameters
     */
    private static function resolvePath(
        string $pathTemplate,
        array $query,
        array $body,
        mixed $routeId,
        array $parameters = [],
    ): string {
        if (! str_starts_with($pathTemplate, '/') || str_starts_with($pathTemplate, '//')) {
            throw new InvalidArgumentException('Strangler routes require one leading slash.');
        }

        $identifier = is_scalar($routeId) ? (string) $routeId : null;

        $pattern = '/\{([^}]+)\}/';

        return (string) preg_replace_callback($pattern, static function (array $matches) use (
            $query,
            $body,
            $identifier,
            $parameters,
        ): string {
            $placeholder = $matches[1];
            $upper = strtoupper($placeholder);

            $value = $parameters[$placeholder] ?? (
                $placeholder === 'id' && $identifier !== null && $identifier !== ''
                ? $identifier
                : ($body[$placeholder] ?? $body[$upper]
                    ?? $query[strtolower($placeholder)] ?? null));

            if (! is_scalar($value) || in_array((string) $value, ['', '.', '..'], true)) {
                throw new InvalidArgumentException('Missing Strangler route parameter.');
            }

            return rawurlencode((string) $value);
        }, $pathTemplate);
    }

    /**
     * Require the web application used by this Yii controller adapter.
     */
    public static function application(): CWebApplication
    {
        return YiiRuntime::application();
    }

    /**
     * Prefer the request identifier before the original body identifier.
     *
     * @param array<array-key, mixed> $payload
     * @param Config                  $config
     */
    private static function routeIdentifier(mixed $id, array $payload, array $config): mixed
    {
        $missingId = $id === null || $id === '';
        if ($missingId && isset($config['bodyIdentifier'])) {
            return $payload[$config['bodyIdentifier']] ?? null;
        }

        return $id;
    }

    /**
     * Adapt the request contract and preserve original values for route substitution.
     *
     * @param Config                                                     $config
     * @param Route                                                      $route
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     *
     * @return Prepared|false
     */
    private static function prepareRequest(
        CController $controller,
        array $config,
        string $actionKey,
        array $route,
        array $snapshot,
        ?InlineMapping $inline,
    ): array|false {
        $bound = self::bodyBindings($route['fromBody'] ?? [], $snapshot, $inline);
        if ($bound === false) {
            return false;
        }
        $payload = $snapshot['payload'];
        $context = $inline?->context() ?? [
            'action' => $actionKey, 'query' => $_GET, 'payload' => $payload,
        ];
        $parameters = self::routeParameters(
            $controller, $config, $route, $context, $bound['parameters'],
        );
        if ($parameters === false) {
            return false;
        }
        $request = self::application()->getRequest();
        $query = $_GET;
        unset($query['r']);

        $routeId = self::routeIdentifier($request->getParam('id'), $payload, $config);
        $mapped = self::requestValues(
            $config, $actionKey, $route['path'], $query, $snapshot, $inline, $bound['payload'],
        );
        if ($mapped === false) {
            return false;
        }

        $pathQuery = array_replace(
            array_change_key_case($query),
            array_change_key_case($mapped['query']),
        );

        return [
            'outgoing' => [
                'method'       => strtoupper($route['method']),
                'path'         => $route['path'],
                'query'        => $mapped['query'],
                'payload'      => $mapped['payload'],
                'body'         => $mapped['body'],
            ],
            'pathQuery'  => $pathQuery,
            'routeId'    => $routeId,
            'parameters' => $parameters,
        ];
    }

    /**
     * Bind declared route values without rewriting untouched request bodies.
     *
     * @param array<string, string|list<string>>                         $bindings
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     *
     * @return array{
     *     parameters: array<string, int|string>, payload: array<array-key, mixed>|\stdClass|null
     * }|false
     */
    private static function bodyBindings(
        array $bindings, array $snapshot, ?InlineMapping $inline,
    ): array|false {
        if ($bindings === []) {
            return ['parameters' => [], 'payload' => null];
        }

        return $inline?->bindBodyParameters($bindings, $snapshot['payload'])
            ?? BodyParameters::resolve(
                $bindings,
                BodyParameters::source($snapshot['payload'], $snapshot['body']),
            );
    }

    /**
     * Keep native lookup results separate from transmitted query and body values.
     *
     * @param Config                    $config
     * @param Route                     $route
     * @param Context                   $context
     * @param array<string, int|string> $bound
     *
     * @return array<string, int|string>|false
     */
    private static function routeParameters(
        CController $controller, array $config, array $route, array $context, array $bound,
    ): array|false {
        $resolver = $config['routeParameters'] ?? null;
        $resolved = $resolver === null ? [] : $resolver($context);
        if ($resolved === false) {
            return false;
        }
        $native = self::nativeParentParameters($controller, $config, $route, $context);
        if ($native === false) {
            return false;
        }
        $parameters = self::mergeRouteParameters($bound, $native);

        return $parameters === false ? false : self::mergeRouteParameters($parameters, $resolved);
    }

    /**
     * Resolve scoped ownership only when the selected outgoing route requires it.
     *
     * @param Config  $config
     * @param Route   $route
     * @param Context $context
     *
     * @return array<string, int|string>|false
     */
    private static function nativeParentParameters(
        CController $controller, array $config, array $route, array $context,
    ): array|false {
        $parent = $config['nativeParent'] ?? null;
        if ($parent === null || ! str_contains($route['path'], '{'.strtolower($parent).'}')) {
            return [];
        }
        $contract = $config['controllerContract']
            ?? throw new InvalidArgumentException('within() requires a controller contract.');

        return $contract->parentParameters(
            $controller, $parent, $context['query'], $context['payload'] ?? [],
            $config['bodyIdentifier'] ?? null,
        );
    }

    /**
     * Merge declared and native route values without hiding conflicting identifiers.
     *
     * @param array<string, int|string> $bound
     * @param array<string, mixed>      $resolved
     *
     * @return array<string, int|string>|false
     */
    private static function mergeRouteParameters(array $bound, array $resolved): array|false
    {
        $parameters = $bound;
        foreach ($resolved as $name => $value) {
            if (! self::validRouteParameter($value)
                || (isset($bound[$name]) && (string) $bound[$name] !== (string) $value)) {
                return false;
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * Reject values that cannot identify one safe route segment.
     *
     * @phpstan-assert-if-true int|string $value
     */
    private static function validRouteParameter(mixed $value): bool
    {
        return (is_string($value) || is_int($value))
            && ! in_array((string) $value, ['', '.', '..'], true);
    }

    /**
     * Adapt original values through the configured contract boundary.
     *
     * @param Config                                                     $config
     * @param array<array-key, mixed>                                    $query
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     * @param array<array-key, mixed>|\stdClass|null                     $remaining
     *
     * @return RequestValues|false
     */
    private static function requestValues(
        array $config,
        string $actionKey,
        string $path,
        array $query,
        array $snapshot,
        ?InlineMapping $inline,
        array|\stdClass|null $remaining,
    ): array|false {
        $requestSnapshot = $snapshot;
        if ($remaining !== null && $inline === null) {
            $requestSnapshot = [
                'payload' => (array) $remaining,
                'body'    => json_encode(
                    $remaining, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                ),
            ];
        }
        if ($inline !== null) {
            try {
                return $inline->request(
                    $query, $requestSnapshot['payload'], $requestSnapshot['body'], $remaining,
                );
            } catch (Throwable $exception) {
                YiiRuntime::logRejection($config, $actionKey, $path, 'request_adaptation_failure');

                throw $exception;
            }
        }
        $body = $requestSnapshot['body'];
        if ($body !== null && trim($body) === '') {
            $body = '[]';
        }

        return [
            'query' => $query, 'payload' => $requestSnapshot['payload'], 'body' => $body,
        ];
    }
}
