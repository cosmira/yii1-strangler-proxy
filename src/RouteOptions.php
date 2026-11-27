<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CController;
use Closure;

/**
 * Configure mappings and fallback for one Yii action.
 *
 * @phpstan-import-type QueryMapper from InlineMapping
 * @phpstan-import-type ResponseMapper from InlineMapping
 *
 * @phpstan-type Fallback Closure(array<array-key, mixed>, CController): bool
 */
final class RouteOptions
{
    /** @var Fallback|null */
    private ?Closure $fallback = null;

    /** @internal */
    public function __construct(private readonly Strangler $proxy, private readonly string $action) {}

    /** @param QueryMapper $mapper */
    public function query(array|callable $mapper): self
    {
        $this->proxy->mapQuery($mapper, [$this->action]);

        return $this;
    }

    /** @param ResponseMapper $mapper */
    public function response(callable $mapper): self
    {
        $this->proxy->mapJsonResponse($mapper, [$this->action]);

        return $this;
    }

    /** @param ResponseMapper $mapper */
    public function errors(callable $mapper): self
    {
        $this->proxy->mapJsonErrors($mapper, [$this->action]);

        return $this;
    }

    /** @param array<string, string> $names */
    public function files(array $names): self
    {
        $this->proxy->mapFiles($names, [$this->action]);

        return $this;
    }

    /** @param callable(array<array-key, mixed>, CController): bool $condition */
    public function fallbackWhen(callable $condition): self
    {
        $this->fallback = $condition(...);

        return $this;
    }

    /**
     * @internal
     *
     * @return Fallback|null
     */
    public function fallback(): ?Closure
    {
        return $this->fallback;
    }
}
