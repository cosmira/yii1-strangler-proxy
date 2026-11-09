<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Schema;

use Closure;

/**
 * Keep requested response relations and forwarded includes on the same selection.
 *
 * @internal
 *
 * @phpstan-type NativeDefaults array<string, mixed>|callable(
 *     array<array-key, mixed>
 * ): ?array<string, mixed>
 * @phpstan-type SelectionMapper callable(
 *     array<array-key, mixed>, array{action: string}
 * ): (array<array-key, mixed>|false)
 */
final readonly class Selection
{
    /**
     * Native selection resolved only when an action needs its existing defaults.
     *
     * @var Closure(array<array-key, mixed>): ?array<string, mixed>
     */
    private Closure $defaults;

    /**
     * Declare action-specific differences from explicit with/merge_with selection.
     *
     * @param callable(array<array-key, mixed>): ?array<string, mixed> $defaults
     * @param list<string>                                             $replaceFor
     * @param list<string>                                             $emptyFor
     * @param array<string, list<string>>                              $extendFor
     * @param list<string>                                             $mergeFor
     */
    public function __construct(
        callable $defaults,
        private array $replaceFor = [],
        private array $emptyFor = [],
        private array $extendFor = [],
        private array $mergeFor = ['view'],
    ) {
        $this->defaults = $defaults(...);
    }

    /**
     * Resolve explicit native defaults and action-specific selection rules as one policy.
     *
     * @param NativeDefaults|null         $defaults
     * @param list<string>                $replaceFor
     * @param list<string>                $emptyFor
     * @param array<string, list<string>> $extendFor
     * @param list<string>                $mergeFor
     */
    public static function make(
        array|callable|null $defaults, array $replaceFor, array $emptyFor,
        array $extendFor, array $mergeFor, ?self $current = null,
    ): ?self {
        $replace = array_map(strtolower(...), $replaceFor);
        $empty = array_map(strtolower(...), $emptyFor);
        $extend = array_change_key_case($extendFor, CASE_LOWER);
        $merge = array_map(strtolower(...), $mergeFor);
        $nativeDefaults = $defaults;
        if ($nativeDefaults === null) {
            if ($replace === [] && $empty === [] && $extend === [] && $merge === ['view']) {
                return null;
            }
            $nativeDefaults = $current->defaults
                ?? throw new \InvalidArgumentException(
                    'Selection rules require query(defaults: ...).',
                );
        }

        return new self(
            is_callable($nativeDefaults) ? $nativeDefaults : static fn (): array => $nativeDefaults,
            $replace, $empty, $extend, $merge,
        );
    }

    /**
     * Resolve response projection without sharing mutable state between requests.
     *
     * @param array{action: string, query?: array<array-key, mixed>} $context
     *
     * @return array<string, mixed>|null
     */
    public function projection(array $context): ?array
    {
        $query = $context['query'] ?? [];
        $parser = Includes::all();
        $requested = $parser->normalize($query['with'] ?? []);
        $merged = $parser->normalize($query['merge_with'] ?? []);
        if ($requested === null || $merged === null) {
            return null;
        }
        $action = $context['action'];
        $selected = $this->requestedProjection($action, $query, $requested);
        if ($selected !== null) {
            return $selected;
        }
        $defaults = ($this->defaults)($context);
        if (in_array($action, $this->replaceFor, true)) {
            return $defaults;
        }

        return array_replace(
            $defaults ?? [], in_array($action, $this->mergeFor, true) ? $merged : [],
        );
    }

    /**
     * Prefer explicit relation selection unless an action declares a different policy.
     *
     * @param array<array-key, mixed> $query
     * @param array<string, mixed>    $requested
     *
     * @return array<string, mixed>|null
     */
    private function requestedProjection(string $action, array $query, array $requested): ?array
    {
        if (in_array($action, $this->emptyFor, true)) {
            return [];
        }
        if (array_key_exists($action, $this->extendFor)) {
            return array_key_exists('with', $query) && $query['with'] === []
                ? [] : array_replace(array_fill_keys($this->extendFor[$action], []), $requested);
        }

        return $requested !== [] && ! in_array($action, $this->replaceFor, true)
            ? $requested : null;
    }

    /**
     * Forward the selected top-level relation names while retaining response depth.
     *
     * @param array<array-key, mixed>                                $query
     * @param array{action: string, query?: array<array-key, mixed>} $context
     * @param SelectionMapper                                        $mapper
     *
     * @return array<array-key, mixed>|false
     */
    public function query(array $query, array $context, callable $mapper): array|false
    {
        $projection = $this->projection(array_replace($context, ['query' => $query]));
        if ($projection === null) {
            return false;
        }
        $query['with'] = array_keys($projection);
        $query['merge_with'] = [];

        return $mapper($query, $context);
    }
}
