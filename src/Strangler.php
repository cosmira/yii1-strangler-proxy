<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use InvalidArgumentException;

/**
 * Build the Yii filter configuration for migrated actions.
 *
 * @phpstan-type Config array{
 *     feature?: string,
 *     routes?: array<string, array{method: string, path: string}>,
 *     adapter?: StranglerAdapterInterface|string|array<string, mixed>,
 *     bodyIdentifier?: string,
 *     payloadReader?: callable(CController): array<array-key, mixed>,
 *     bypass?: callable(CController, string, array<array-key, mixed>): bool,
 *     afterAttempt?: callable(string, string): void,
 *     bypassPayloadFields?: array<string, list<string>>
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
     * @var array<string, array{method: string, path: string}>
     */
    private array $routes = [];

    /**
     * Payload fields that keep each action on the legacy implementation.
     *
     * @var array<string, list<string>>
     */
    private array $bypassPayloadFields = [];

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
     * Configure the adapter that translates the API contract.
     *
     * @param StranglerAdapterInterface|string|array<string, mixed> $adapter
     */
    public function usingAdapter(StranglerAdapterInterface|string|array $adapter): self
    {
        $this->config['adapter'] = $adapter;

        return $this;
    }

    /**
     * Read the legacy payload through an application callback.
     *
     * @param callable(CController): array<array-key, mixed> $reader
     */
    public function payloadUsing(callable $reader): self
    {
        $this->config['payloadReader'] = $reader;

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
     * Set the fallback payload field for route identifiers.
     */
    public function bodyIdentifier(string $field): self
    {
        $this->config['bodyIdentifier'] = $field;

        return $this;
    }

    /**
     * Keep an action on the legacy implementation when any listed field is present.
     *
     * @param list<string> $fields
     */
    public function bypassWhenPayloadHas(string $action, array $fields): self
    {
        $this->bypassPayloadFields[strtolower($action)] = $fields;

        return $this;
    }

    /**
     * Map a legacy action to an upstream GET route.
     */
    public function get(string $action, string $path): self
    {
        return $this->route($action, 'GET', $path);
    }

    /**
     * Map a legacy action to an upstream POST route.
     */
    public function post(string $action, string $path): self
    {
        return $this->route($action, 'POST', $path);
    }

    /**
     * Map a legacy action to an upstream PUT route.
     */
    public function put(string $action, string $path): self
    {
        return $this->route($action, 'PUT', $path);
    }

    /**
     * Map a legacy action to an upstream DELETE route.
     */
    public function delete(string $action, string $path): self
    {
        return $this->route($action, 'DELETE', $path);
    }

    /**
     * Map a legacy action to an upstream PATCH route.
     */
    public function patch(string $action, string $path): self
    {
        return $this->route($action, 'PATCH', $path);
    }

    /**
     * Map a legacy action to an upstream HEAD route.
     */
    public function head(string $action, string $path): self
    {
        return $this->route($action, 'HEAD', $path);
    }

    /**
     * Export the Yii filter definition with its routes and bypass fields.
     *
     * @return array{0: class-string<StranglerFilter>, config: Config}
     */
    public function build(): array
    {
        return [StranglerFilter::class, 'config' => array_merge($this->config, [
            'routes'              => $this->routes,
            'bypassPayloadFields' => $this->bypassPayloadFields,
        ])];
    }

    /**
     * Register a route using a case-insensitive action key.
     */
    private function route(string $action, string $method, string $path): self
    {
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

        $this->routes[$key] = ['method' => $method, 'path' => $path];

        return $this;
    }
}
