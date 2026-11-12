<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;

/**
 * Translate legacy queries without exposing field mapping to modifiers.
 *
 * @phpstan-type Modifier callable(
 *     array<array-key, mixed>, array{action: string}
 * ): (array<array-key, mixed>|false)
 */
final class Query
{
    /**
     * Value differences applied after query names and list values are normalized.
     *
     * @var array<string, callable(mixed): mixed>
     */
    private array $valueFormatters = [];

    /**
     * Legacy filter names mapped to upstream filter names.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $filterFields = null;
    /**
     * Legacy sort fields accepted by the upstream query.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $sortFields = null;
    /**
     * Legacy relation names mapped to upstream includes.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $includeFields = null;
    /**
     * Actions that retain offset pagination during query translation.
     *
     * @var list<string>
     */
    private array $listActions = ['get'];
    /**
     * Transport-only query keys removed before supported-query checks.
     *
     * @var list<string>
     */
    private array $ignored = ['id', '_'];
    /**
     * Page size used when the client omits its limit.
     */
    private int $defaultLimit = 20;
    /**
     * Largest page size accepted by the upstream contract.
     */
    private int $maxLimit = 100;

    /**
     * Application semantics applied with this query's unchanged defaults.
     *
     * @var Closure(
     *     array<array-key, mixed>, array{action: string}, self
     * ): (array<array-key, mixed>|false)|null
     */
    private ?Closure $adapter = null;

    /**
     * Immutable configuration supplied to an adapted query.
     */
    private self $defaults;

    /**
     * Keep optional automatic names separate from explicit restrictions.
     *
     * @param Fields $convention An explicit empty schema keeps the legacy default strict.
     */
    private function __construct(private Fields $convention) {}

    /**
     * Start with legacy list pagination and transport-key defaults.
     */
    public static function legacy(?Fields $convention = null): self
    {
        return new self($convention ?? Fields::uppercase([]));
    }

    /**
     * Normalize action names and reject invalid collection page sizes.
     *
     * @param array<string, int> $actions
     *
     * @return array<string, int>
     */
    public static function collectionPages(array $actions): array
    {
        $configured = [];
        foreach ($actions as $action => $pageSize) {
            if ($action === '' || $pageSize < 1) {
                throw new InvalidArgumentException(
                    'Complete collection actions require nonempty names and positive page sizes.',
                );
            }
            $configured[strtolower($action)] = $pageSize;
        }

        return $configured;
    }

    /**
     * Format declared filter values while forwarding every other filter unchanged.
     *
     * @param array<string, callable(mixed): mixed> $formatters
     */
    public function values(array $formatters): self
    {
        $copy = clone $this;
        $copy->valueFormatters = $formatters;

        return $copy;
    }

    /**
     * Select the legacy filters that can reach the upstream API.
     *
     * @param array<array-key, mixed>|Fields $fields
     */
    public function filters(array|Fields $fields): self
    {
        $copy = clone $this;
        $copy->filterFields = $fields instanceof Fields ? $fields->filters() : $fields;

        return $copy;
    }

    /**
     * Translate supported legacy ordering fields to upstream names.
     *
     * @param array<array-key, mixed>|Fields $fields
     */
    public function sorts(array|Fields $fields): self
    {
        $copy = clone $this;
        $copy->sortFields = $fields instanceof Fields ? $fields->sorts() : $fields;

        return $copy;
    }

    /**
     * Translate supported legacy relations to upstream includes.
     *
     * @param array<array-key, mixed>|Fields $fields
     */
    public function includes(array|Fields $fields): self
    {
        $copy = clone $this;
        $copy->includeFields = $fields instanceof Fields ? $fields->inverse() : $fields;

        return $copy;
    }

    /**
     * Set the default page size and the upstream page-size ceiling.
     */
    public function pagination(int $default = 20, int $max = 100): self
    {
        $copy = clone $this;
        $copy->defaultLimit = $default;
        $copy->maxLimit = $max;

        return $copy;
    }

    /**
     * Replace the actions that forward translated pagination.
     *
     * @param list<string> $actions
     */
    public function lists(array $actions): self
    {
        $copy = clone $this;
        $copy->listActions = $actions;

        return $copy;
    }

    /**
     * Replace the transport-only keys removed before query validation.
     *
     * @param list<string> $fields
     */
    public function ignore(array $fields): self
    {
        $copy = clone $this;
        $copy->ignored = $fields;

        return $copy;
    }

    /**
     * Add application semantics without rebuilding field or include conventions.
     *
     * @internal
     *
     * @param callable(
     *     array<array-key, mixed>, array{action: string}, self
     * ): (array<array-key, mixed>|false) $adapter
     */
    public function adapt(callable $adapter): self
    {
        $copy = clone $this;
        $copy->adapter = $adapter(...);
        $copy->defaults = $this;

        return $copy;
    }

    /**
     * Change query structure before applying the configured naming and pagination.
     *
     * @param Modifier $modify
     */
    public function modify(callable $modify): self
    {
        return $this->adapt(static function (
            array $query, array $context, self $defaults,
        ) use ($modify): array|false {
            $modified = $modify($query, $context);

            return $modified === false ? false : $defaults($modified, $context);
        });
    }

    /**
     * Translate supported queries and retain legacy execution for unsupported values.
     *
     * @param array<array-key, mixed> $query
     * @param array{action: string}   $context
     *
     * @return array<array-key, mixed>|false
     */
    public function __invoke(array $query, array $context): array|false
    {
        if ($this->adapter !== null) {
            return ($this->adapter)($query, $context, $this->defaults);
        }
        $values = $this->nativeQuery($query);
        $list = in_array($context['action'], $this->listActions, true);
        if (! $list) {
            unset($values['start'], $values['limit']);
        }

        try {
            $filters = $this->filterFields
                ?? $this->convention->filters(self::names(self::keys($values['filter'] ?? [])));
            $sorts = $this->sortFields
                ?? $this->convention->sorts(self::names(self::keys($values['sort'] ?? [])));
            $includes = $this->includeNames($values['with'] ?? []);
        } catch (InvalidArgumentException) {
            return false;
        }
        $mapped = Mapping::legacyQuery($values, $filters, $sorts,
            $includes, $this->defaultLimit, $this->maxLimit);
        if ($mapped === false) {
            return false;
        }
        if (! $list) {
            unset($mapped['page'], $mapped['per_page']);
        }
        $filterValues = $mapped['filter'] ?? [];
        if ($filterValues !== []) {
            $mapped['filter'] = $this->formatValues($filterValues);
        }

        return $mapped;
    }

    /**
     * Strip ignored inputs and normalize Yii's scalar relation flags before mapping.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    private function nativeQuery(array $query): array
    {
        $values = array_diff_key($query, array_flip($this->ignored));
        if (isset($values['with']) && is_array($values['with'])) {
            $values['with'] = self::nativeIncludes($values['with']);
        }

        return $values;
    }

    /**
     * Native scalar include flags select by key presence, regardless of their value.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     */
    private static function nativeIncludes(array $values): array
    {
        $names = [];
        foreach ($values as $key => $value) {
            $names[] = is_string($key) && is_scalar($value) ? $key : $value;
        }

        return $names;
    }

    /**
     * Apply value differences to the normalized filter representation.
     *
     * @param array<string, string|int|float|bool> $filters
     *
     * @return array<string, mixed>
     */
    private function formatValues(array $filters): array
    {
        $formatted = $filters;
        foreach ($this->valueFormatters as $field => $format) {
            if (array_key_exists($field, $filters)) {
                $formatted[$field] = $format($filters[$field]);
            }
        }

        return $formatted;
    }

    /**
     * Apply explicit relation differences and resolve other names through the convention.
     *
     * @return array<array-key, mixed>
     */
    private function includeNames(mixed $values): array
    {
        $declared = $this->includeFields ?? [];
        $ordinary = array_values(array_diff(self::names($values), array_keys($declared)));

        return $declared + $this->convention->inverse($ordinary);
    }

    /**
     * Read query field keys without accepting malformed filter or sort containers.
     *
     * @return list<array-key>
     */
    private static function keys(mixed $values): array
    {
        return is_array($values) ? array_keys($values) : [];
    }

    /**
     * Resolve supplied names only; Mapping validates original query shapes and values.
     *
     * @return list<string>
     */
    private static function names(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $names = [];
        foreach ($values as $name) {
            if (! is_string($name)) {
                return [];
            }
            $names[] = $name;
        }

        return $names;
    }
}
