<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;

/**
 * Describe the destination of a selected Yii action.
 *
 * @phpstan-import-type ActionHandler from Strangler
 * @phpstan-import-type ResponseMapper from InlineMapping
 */
final readonly class Forward
{
    /**
     * Store a destination that can be bound to the action's shared declaration.
     *
     * @param Closure(Strangler, string, string): Strangler $register
     */
    private function __construct(private Closure $register) {}

    /**
     * Forward to a POST endpoint with optional prepared Yii fields.
     *
     * @param array<array-key, mixed>|null $payload
     * @param array<string, int|string>    $parameters
     */
    public static function post(
        string $path, ?array $payload = null, ?int $expects = null, array $parameters = [],
    ): self {
        return self::route('POST', $path, $payload, $expects, $parameters);
    }

    /**
     * Forward to a PUT endpoint with optional prepared Yii fields.
     *
     * @param array<array-key, mixed>|null $payload
     * @param array<string, int|string>    $parameters
     */
    public static function put(
        string $path, ?array $payload = null, ?int $expects = null, array $parameters = [],
    ): self {
        return self::route('PUT', $path, $payload, $expects, $parameters);
    }

    /**
     * Forward to any supported upstream method and apply the shared route mappings.
     *
     * @param array<array-key, mixed>|null $payload
     * @param array<string, int|string>    $parameters
     */
    public static function route(
        string $method, string $path, ?array $payload = null, ?int $expects = null, array $parameters = [],
    ): self {
        return new self(static fn (Strangler $routes, string $action, string $incoming): Strangler => $routes->route(
            $action, $method, $path,
            body: $payload === null ? null : static fn (): array => $payload,
            parameters: $parameters === [] ? null : static fn (): array => $parameters,
            expects: $expects, incoming: $incoming,
        ),
        );
    }

    /**
     * Run a sequence under the original action and its existing request guards.
     *
     * @param ActionHandler       $requests
     * @param ResponseMapper|null $errors
     */
    public static function sequence(
        callable $requests, bool $checkNative = false, ?callable $errors = null,
    ): self {
        return new self(static function (Strangler $routes, string $action, string $incoming) use (
            $requests, $checkNative, $errors,
        ): Strangler {
            $routes->action($action, $incoming, $requests, checkNative: $checkNative);

            return $errors === null ? $routes : $routes->mapJsonErrors($errors, [$action]);
        });
    }

    /**
     * Attach the destination to the action selected for this request.
     *
     * @internal
     */
    public function register(Strangler $routes, string $action, string $incoming): Strangler
    {
        return ($this->register)($routes, $action, $incoming);
    }
}
