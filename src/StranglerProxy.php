<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use Cosmira\Strangler\Internal\Payload\BodyParameters;
use Cosmira\Strangler\Internal\Payload\NativeRequestGuard;
use Cosmira\Strangler\Response\Json;
use CWebApplication;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @phpstan-import-type Config from Strangler
 * @phpstan-import-type Action from Strangler
 * @phpstan-import-type Choice from Strangler
 * @phpstan-import-type Route from Strangler
 * @phpstan-import-type Context from InlineMapping
 * @phpstan-import-type RequestValues from InlineMapping
 *
 * @phpstan-type Prepared array{
 *     outgoing: array{method: string, path: string, expects: int|null,
 *         query: array<array-key, mixed>, payload: array<array-key, mixed>, body: string|null},
 *     pathQuery: array<array-key, mixed>, routeId: mixed, parameters: array<string, int|string>
 * }
 * @phpstan-type Snapshot array{payload: array<array-key, mixed>, body: string|null}
 */
final class StranglerProxy
{
    /**
     * Send upstream attempts through the HTTP transport boundary.
     */
    private readonly UpstreamTransport $transport;

    /**
     * Send responses through the Yii output boundary.
     */
    private readonly ResponseEmitter $emitter;

    /**
     * Carry the owning filter's observer into the action's individual HTTP attempts.
     *
     * @var Config
     */
    private array $requestConfig = [];

    /**
     * Attribute intermediate failures to the originating action in Yii diagnostics.
     */
    private string $requestAction = 'request';

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
     * Send one application-defined step to the configured upstream without ending the Yii request.
     *
     * @param array<array-key, mixed>      $query
     * @param array<array-key, mixed>|null $body
     */
    public function request(
        string $method, string $path, array $query = [], ?array $body = null,
        ?int $expects = null,
    ): ResponseInterface {
        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
            || ! preg_match('~^/(?!/)[^?#\\\\\x00-\x20]*$~D', $path)
            || preg_match('~(?:^|/)[.]{1,2}(?:/|$)~', rawurldecode($path))) {
            throw new InvalidArgumentException(
                'Expected a local upstream resource path and HTTP method.',
            );
        }

        return $this->transport->request(
            $method, $path, $query, $body, $expects, $this->requestConfig, $this->requestAction,
        );
    }

    /**
     * Confirm one REST write before the action continues. A 204 receipt must be empty.
     *
     * @param array<array-key, mixed>      $query
     * @param array<array-key, mixed>|null $body
     * @param (callable(mixed): bool)|null $confirm Accept the decoded acknowledgement.
     */
    public function write(
        string $method, string $path, array $query = [], ?array $body = null,
        int $expects = 200, ?callable $confirm = null,
    ): ResponseInterface {
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('A write requires POST, PUT, PATCH or DELETE.');
        }
        $response = $this->request($method, $path, $query, $body, $expects);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            return $response;
        }
        if ($status !== $expects) {
            return Json::invalidResponse($response);
        }
        if ($status === 204) {
            return (string) $response->getBody() === ''
                ? $response : Json::invalidResponse($response);
        }

        return $confirm === null ? $response : self::confirmJson($response, $confirm);
    }

    /**
     * Leave confirmed response bytes readable even when the upstream stream cannot rewind.
     *
     * @param callable(mixed): bool $confirm
     */
    private static function confirmJson(
        ResponseInterface $response, callable $confirm,
    ): ResponseInterface {
        $receipt = (string) $response->getBody();

        try {
            $json = json_decode($receipt, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Json::invalidResponse($response);
        }

        return $confirm($json) === true
            ? $response->withBody(Utils::streamFor($receipt)) : Json::invalidResponse($response);
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

        if (self::declaration($config, $actionKey) === null) {
            YiiRuntime::logDecision($config, $actionKey, 'route_unmapped');

            return false;
        }

        return true;
    }

    /**
     * Find a Yii action's destination in the filter declaration.
     *
     * @param Config $config
     *
     * @return Action|Choice|Route|null
     */
    private static function declaration(array $config, string $action): ?array
    {
        $routes = $config['routes'] ?? [];
        $actions = $config['actions'] ?? [];
        $choices = $config['choices'] ?? [];

        return $routes[$action] ?? $actions[$action] ?? $choices[$action] ?? null;
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
        $route = self::declaration($config, $actionKey);
        if (self::queryIsNative($controller, $config, $actionKey, $route)) {
            return true;
        }
        if (isset($route['choose'])) {
            $original = $payload === null
                ? self::readPayload(
                    $controller, $config['actionOptions'] ?? [], $actionKey, $route['incoming'],
                )
                : ['payload' => $payload, 'body' => null];
            $selected = $route['choose']($original['payload'], $_GET);
            if ($selected === null) {
                return true;
            }
            $destination = $selected->build()['config'];
            $form = self::readPayload($controller, $destination, $actionKey, snapshot: $original);

            return self::shouldBypass($controller, $destination, $actionKey, $form['payload']);
        }
        if (isset($route['run'])) {
            return self::actionIsNative(
                $controller, $config['actionOptions'] ?? [], $actionKey, $route, $payload,
            );
        }
        $bound = ControllerContract::resolveConfig($config, $controller);

        return self::payloadIsNative(
            $controller, $bound, $actionKey, $payload, ControllerContract::forModel($bound),
        );
    }

    /**
     * Resolve declared native searches and application bypasses before model binding.
     *
     * @param Config                   $config
     * @param Action|Choice|Route|null $route
     */
    private static function queryIsNative(
        CController $controller, array $config, string $action, ?array $route,
    ): bool {
        $method = self::application()->getRequest()->getRequestType();
        $incoming = $route['incoming'] ?? null;
        if (($incoming !== null && $incoming !== $method)
            || NativeRequestGuard::hasNativeFilter($config['nativeQueryFilters'] ?? [], $_GET)) {
            return true;
        }
        $bypass = $config['bypass'] ?? null;

        return ($bypass !== null && $bypass($controller, $action, $_GET))
            || (isset($route['fallback']) && $route['fallback']($_GET, $controller));
    }

    /**
     * Evaluate payload eligibility once against the original native request snapshot.
     *
     * @param Config                       $config
     * @param array<array-key, mixed>|null $payload
     */
    private static function payloadIsNative(
        CController $controller, array $config, string $actionKey, ?array $payload,
        ?ControllerContract $contract = null,
    ): bool {
        $predicate = $config['bypassPayload'] ?? null;
        $guards = $config['requestGuards'] ?? [];
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

        return $contract !== null
            && self::hasNativeRelationWrite($controller, $contract, $config, $actionKey, $original);
    }

    /**
     * Keep unadapted nested writes local after consuming declared route parameters.
     *
     * @param Config                  $config
     * @param array<array-key, mixed> $payload
     */
    private static function hasNativeRelationWrite(
        CController $controller, ControllerContract $contract, array $config,
        string $action, array $payload,
    ): bool {
        $relations = in_array($action, ['create', 'edit'], true)
            ? $contract->nativeRelations($controller) : [];
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
        return $this->dispatch($controller, $config, self::normalizeActionKey($actionId));
    }

    /**
     * A chosen destination consumes the same native form and raw body as its selector.
     *
     * @param Config        $config
     * @param Snapshot|null $snapshot
     */
    private function dispatch(
        CController $controller, array $config, string $actionKey, ?array $snapshot = null,
    ): bool {
        $route = self::declaration($config, $actionKey);
        if ($route === null) {
            YiiRuntime::logDecision($config, $actionKey, 'route_unmapped');

            return false;
        }

        if (self::queryIsNative($controller, $config, $actionKey, $route)) {
            YiiRuntime::logDecision($config, $actionKey, 'bypass_callback');

            return false;
        }
        $bound = isset($route['method']) ? ControllerContract::resolveConfig($config, $controller)
            : ($config['actionOptions'] ?? []);
        unset($bound['bypass'], $bound['nativeQueryFilters']);

        $context = [
            'action'        => $actionKey, 'query' => $_GET, 'payload' => null,
            'method'        => $route['method'] ?? $route['incoming'],
            'requestMethod' => self::application()->getRequest()->getRequestType(),
        ];
        if (! isset($route['method'])) {
            $context['plainJson'] = true;
        }
        if (isset($bound['bodyIdentifier'])) {
            $context['identifier'] = $bound['bodyIdentifier'];
        }
        $inline = isset($bound['mappings'])
            ? new InlineMapping($bound['mappings'], $context) : null;

        try {
            $original = self::readPayload(
                $controller, $bound, $actionKey,
                isset($route['choose']) ? $route['incoming'] : null, $snapshot,
            );
        } catch (JsonException) {
            $this->emitter->error(400, 'strangler_invalid_payload', $inline);

            return true;
        }
        if (isset($route['choose'])) {
            $selected = $route['choose']($original['payload'], $_GET);
            if ($selected === null) {
                YiiRuntime::logDecision($config, $actionKey, 'bypass_payload');

                return false;
            }

            return $this->dispatch(
                $controller, $selected->build()['config'], $actionKey, $original,
            );
        }
        if (isset($route['run'])) {
            return $this->runAction(
                $controller, $bound, $actionKey, $route, $original['payload'],
                $inline?->withPayload($original['payload'], $original['body']),
            );
        }

        return $this->forward($controller, $bound, $actionKey, $route, $inline, $original);
    }

    /**
     * Run application requests only after native guards and mandatory checks have passed.
     *
     * @param Config                  $config
     * @param Action                  $route
     * @param array<array-key, mixed> $payload
     */
    private function runAction(
        CController $controller, array $config, string $action,
        array $route, array $payload, ?InlineMapping $inline,
    ): bool {
        if (self::actionIsNative($controller, $config, $action, $route, $payload)) {
            YiiRuntime::logDecision($config, $action, 'bypass_payload');

            return false;
        }
        $before = $config['beforeRequest'] ?? null;
        if ($before !== null) {
            $before($payload, $action, $controller);
        }
        $api = clone $this;
        $api->requestConfig = $config;
        $api->requestAction = $action;
        $steps = $route['run']($api, $_GET, $payload);
        $this->transport->sendAction($config, $action, $steps, $inline);

        return true;
    }

    /**
     * Check action eligibility against the same original form used for dispatch.
     *
     * @param Config                       $config
     * @param Action                       $route
     * @param array<array-key, mixed>|null $payload
     */
    private static function actionIsNative(
        CController $controller, array $config, string $action, array $route, ?array $payload,
    ): bool {
        $contract = isset($route['checkNative']) ? ControllerContract::forModel($config) : null;
        $original = $payload;
        if ($contract !== null) {
            $incoming = $route['incoming'];
            $original ??= self::readPayload($controller, $config, $action, $incoming)['payload'];
            $identifier = $config['bodyIdentifier'] ?? null;
            if ($contract->shouldBypass(
                $controller, $action, $_GET, $original, $identifier, $incoming,
            )) {
                return true;
            }
        }

        return self::payloadIsNative($controller, $config, $action, $original, $contract);
    }

    /**
     * Resolve the upstream route and prepare the native payload for transport.
     *
     * @param Config                                                     $config
     * @param Route                                                      $route
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     */
    private function forward(
        CController $controller,
        array $config,
        string $actionKey,
        array $route,
        ?InlineMapping $inline,
        array $snapshot,
    ): bool {
        $payload = $snapshot['payload'];
        $contract = ControllerContract::forModel($config);
        $nativeBypass = $contract !== null
            && $contract->shouldBypass(
                $controller, $actionKey, $_GET, $payload, $config['bodyIdentifier'] ?? null,
                $route['incoming'] ?? null,
            );
        if ($nativeBypass
            || self::payloadIsNative($controller, $config, $actionKey, $payload, $contract)) {
            YiiRuntime::logDecision($config, $actionKey, 'bypass_payload');

            return false;
        }
        $mapping = $inline?->withPayload($payload, $snapshot['body']);

        $prepared = self::prepareRequest(
            $controller, $config, $actionKey, $route, $snapshot, $mapping,
        );
        if ($prepared === false) {
            YiiRuntime::logDecision($config, $actionKey, 'mapping_unsupported');

            return false;
        }
        $check = $config['beforeRequest'] ?? null;

        return $this->sendPrepared(
            $config, $actionKey, $route['path'], $prepared, $mapping,
            $check === null ? null : static fn () => $check($payload, $actionKey, $controller),
        );
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
        ?Closure $beforeRequest,
    ): bool {
        $outgoing = $prepared['outgoing'];

        try {
            $outgoing['path'] = self::resolvePath(
                $routeTemplate, $prepared['pathQuery'],
                $outgoing['payload'], $prepared['routeId'], $prepared['parameters'],
            );
        } catch (InvalidArgumentException) {
            if (isset($config['controllerContract'])) {
                return false;
            }
            YiiRuntime::logRejection(
                $config, $actionKey, $routeTemplate, 'strangler_route_parameter_missing',
            );
            $this->emitter->error(
                400, 'strangler_route_parameter_missing', $inline,
            );

            return true;
        }
        if ($beforeRequest !== null) {
            $beforeRequest();
        }
        $this->transport->send($config, $actionKey, $outgoing, $routeTemplate, $inline);

        return true;
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
     * @param Config        $config
     * @param Snapshot|null $snapshot
     *
     * @return Snapshot
     */
    private static function readPayload(
        CController $controller,
        array $config,
        string $actionKey,
        ?string $incoming = null,
        ?array $snapshot = null,
    ): array {
        $plain = isset($config['mappings']['jsonFields']);
        $method = $incoming ?? $config['routes'][$actionKey]['method'] ?? null;
        if ($plain && in_array($method, ['GET', 'HEAD'], true)) {
            return ['payload' => [], 'body' => null];
        }
        if ($snapshot !== null) {
            return $plain && $snapshot['body'] !== null
                ? self::plainJsonPayload($snapshot['body']) : $snapshot;
        }
        $reader = $config['routes'][$actionKey]['payloadReader'] ?? $config['payloadReader'] ?? null;
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
        if ($plain) {
            return self::plainJsonPayload($body);
        }
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
     * Keep JSON objects and numbers distinct from lists and strings for typed fields.
     *
     * @return Snapshot
     */
    private static function plainJsonPayload(string $body): array
    {
        $decoded = trim($body) === '' ? null : json_decode($body, flags: JSON_THROW_ON_ERROR);

        return [
            'payload' => is_array($decoded) || $decoded instanceof \stdClass
                ? (array) $decoded : [],
            'body'    => null,
        ];
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

            if (! is_scalar($value) || trim((string) $value) === ''
                || in_array((string) $value, ['.', '..'], true)) {
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
        $select = $route['request'] ?? null;
        if ($select === null) {
            return self::prepareValues(
                $controller, $config, $actionKey, $route, $snapshot, $inline,
            );
        }
        $row = $select($snapshot['payload']);
        if ($row === false || NativeRequestGuard::hasConflictingIdentity(
            $row, $_GET, $config['bodyIdentifier'] ?? null,
        )) {
            return false;
        }

        return self::prepareValues(
            $controller, $config, $actionKey, $route,
            ['payload' => $row, 'body' => null], $inline?->withPayload($row),
        );
    }

    /**
     * Resolve route values and translate the selected input to an upstream request.
     *
     * @param Config                                                     $config
     * @param Route                                                      $route
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     *
     * @return Prepared|false
     */
    private static function prepareValues(
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
                'expects'      => $route['expects'] ?? null,
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
        $resolved = self::mergeRouteParameters([], $resolver === null ? [] : $resolver($context));
        if ($resolved === false) {
            return false;
        }
        $routeResolver = $route['parameters'] ?? null;
        if ($routeResolver !== null) {
            $resolved = self::mergeRouteParameters(
                $resolved, $routeResolver($context['query'], $context['payload'] ?? []),
            );
            if ($resolved === false) {
                return false;
            }
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
     * @param array<string, int|string>  $bound
     * @param array<string, mixed>|false $resolved
     *
     * @return array<string, int|string>|false
     */
    private static function mergeRouteParameters(array $bound, array|false $resolved): array|false
    {
        if ($resolved === false) {
            return false;
        }
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
