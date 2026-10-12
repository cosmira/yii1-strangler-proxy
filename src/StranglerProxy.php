<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;
use CWebApplication;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonException;

/**
 * @phpstan-import-type Config from Strangler
 */
final readonly class StranglerProxy
{
    /**
     * Configuration key for the callback that retains legacy handling.
     */
    private const BYPASS_CALLBACK = 'bypass';

    /**
     * Snapshot field containing the decoded legacy request payload.
     */
    private const PAYLOAD = 'payload';

    /**
     * Route field containing the upstream path template or resolved path.
     */
    private const PATH = 'path';

    /**
     * Snapshot field containing the unmodified JSON request body.
     */
    private const RAW_BODY = 'body';

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
        if (! YiiRuntime::isEnabled($config['feature'] ?? null)) {
            return false;
        }

        $routes = $config['routes'] ?? [];
        $actionKey = self::normalizeActionKey($actionId);

        return isset($routes[$actionKey]);
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
        $bypass = $config[self::BYPASS_CALLBACK] ?? null;
        if ($bypass !== null && $bypass($controller, $actionKey, $_GET)) {
            return true;
        }
        $bypassFields = $config['bypassPayloadFields'] ?? [];
        $fields = $bypassFields[$actionKey] ?? [];

        if ($fields === []) {
            return false;
        }

        $bypassPayload = $payload ?? self::readPayload($controller, $config)[self::PAYLOAD];

        foreach ($fields as $field) {
            if (array_key_exists($field, $bypassPayload)) {
                return true;
            }
        }

        return false;
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
            return false;
        }

        $bypass = $config[self::BYPASS_CALLBACK] ?? null;
        if ($bypass !== null && $bypass($controller, $actionKey, $_GET)) {
            return false;
        }
        unset($config[self::BYPASS_CALLBACK]);

        $adapterConfig = $config['adapter'] ?? null;
        $adapter = YiiRuntime::resolveAdapter($adapterConfig);
        if ($adapter === null && self::hasAdapterConfig($adapterConfig)) {
            YiiRuntime::logRejection(
                $config, $actionKey, $route[self::PATH], 'strangler_adapter_not_configured',
            );
            $this->emitter->error(500, 'strangler_adapter_not_configured');

            return true;
        }

        return $this->forward($controller, $config, $actionKey, $route, $adapter);
    }

    /**
     * Prepare one payload snapshot and resolve the upstream route before sending.
     *
     * @param Config                              $config
     * @param array{method: string, path: string} $route
     */
    private function forward(
        CController $controller,
        array $config,
        string $actionKey,
        array $route,
        ?StranglerAdapterInterface $adapter,
    ): bool {
        try {
            $snapshot = self::readPayload($controller, $config);
            $payload = $snapshot[self::PAYLOAD];
        } catch (JsonException) {
            $this->emitter->error(400, 'strangler_invalid_payload', $adapter, $actionKey);

            return true;
        }

        if (self::shouldBypass($controller, $config, $actionKey, $payload)) {
            return false;
        }

        $prepared = self::prepareRequest($config, $actionKey, $route, $snapshot, $adapter);
        $outgoing = $prepared['outgoing'];

        try {
            $outgoing[self::PATH] = self::resolvePath(
                $route[self::PATH],
                $prepared['pathQuery'],
                $outgoing[self::PAYLOAD],
                $prepared['routeId'],
            );
        } catch (InvalidArgumentException) {
            YiiRuntime::logRejection(
                $config, $actionKey, $route[self::PATH], 'strangler_route_parameter_missing',
            );
            $this->emitter->error(400, 'strangler_route_parameter_missing', $adapter, $actionKey);

            return true;
        }
        $this->transport->send($config, $actionKey, $outgoing, $adapter);

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
     * @param Config $config
     *
     * @return array{payload: array<array-key, mixed>, body: string|null}
     */
    private static function readPayload(CController $controller, array $config): array
    {
        $reader = $config['payloadReader'] ?? null;

        if ($reader !== null) {
            return [self::PAYLOAD => $reader($controller), self::RAW_BODY => null];
        }

        $body = self::application()->getRequest()->getRawBody();
        if (trim($body) === '') {
            return [self::PAYLOAD => [], self::RAW_BODY => '[]'];
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($decoded)) {
            throw new JsonException('Strangler requires a JSON object or array.');
        }

        return [self::PAYLOAD => $decoded, self::RAW_BODY => $body];
    }

    /**
     * Substitute encoded request identifiers into a local upstream path.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     */
    private static function resolvePath(
        string $pathTemplate,
        array $query,
        array $body,
        mixed $routeId,
    ): string {
        if (! str_starts_with($pathTemplate, '/') || str_starts_with($pathTemplate, '//')) {
            throw new InvalidArgumentException('Strangler routes require one leading slash.');
        }

        $identifier = is_scalar($routeId) ? (string) $routeId : null;

        $pattern = '/\{([^}]+)\}/';

        return (string) preg_replace_callback($pattern, static function (array $matches) use (
            $query,
            $body,
            $identifier
        ): string {
            $placeholder = $matches[1];
            $upper = strtoupper($placeholder);

            $value = $placeholder === 'id' && $identifier !== null && $identifier !== ''
                ? $identifier
                : ($body[$placeholder] ?? $body[$upper]
                    ?? $query[strtolower($placeholder)] ?? null);

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
     * @param array{method: string, path: string}                        $route
     * @param array{payload: array<array-key, mixed>, body: string|null} $snapshot
     *
     * @return array{
     *     outgoing: array{method: string, path: string, query: array<array-key, mixed>,
     *         payload: array<array-key, mixed>, body: string|null},
     *     pathQuery: array<array-key, mixed>, routeId: mixed
     * }
     */
    private static function prepareRequest(
        array $config,
        string $actionKey,
        array $route,
        array $snapshot,
        ?StranglerAdapterInterface $adapter,
    ): array {
        $payload = $snapshot[self::PAYLOAD];
        $request = self::application()->getRequest();
        $query = $_GET;
        unset($query['r']);

        $routeId = self::routeIdentifier($request->getParam('id'), $payload, $config);
        $forwardPayload = $payload;
        $forwardQuery = $query;

        if ($adapter !== null) {
            $forwardQuery = $adapter->transformQuery($actionKey, $forwardQuery);
            $forwardPayload = $adapter->transformPayload($actionKey, $forwardPayload);
        }

        $pathQuery = array_replace(
            array_change_key_case($query),
            array_change_key_case($forwardQuery),
        );

        return [
            'outgoing' => [
                'method'       => strtoupper($route['method']),
                self::PATH     => $route[self::PATH],
                'query'        => $forwardQuery,
                self::PAYLOAD  => $forwardPayload,
                self::RAW_BODY => $adapter === null ? $snapshot[self::RAW_BODY] : null,
            ],
            'pathQuery' => $pathQuery,
            'routeId'   => $routeId,
        ];
    }

    /**
     * Determine whether an adapter was explicitly configured.
     */
    private static function hasAdapterConfig(mixed $adapterConfig): bool
    {
        return ! ($adapterConfig === null || $adapterConfig === '' || $adapterConfig === []);
    }
}
