<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Cosmira\Strangler\Schema\Fields;
use InvalidArgumentException;
use stdClass;

/** Pure transformations shared by inline mappings and application adapters. */
final class Mapping
{
    /**
     * Select and rename literal keys while preserving null and container shape.
     *
     * @param array<array-key, mixed>|stdClass $record
     * @param array<array-key, mixed>|Fields   $fields
     *
     * @return ($record is stdClass ? stdClass : array<array-key, mixed>)
     */
    public static function fields(array|stdClass $record, array|Fields $fields): array|stdClass
    {
        $fieldMap = $fields instanceof Fields ? $fields->namesFor($record) : $fields;
        $names = self::names($fieldMap);
        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException('Field maps require distinct target names.');
        }
        $values = $record instanceof stdClass ? get_object_vars($record) : $record;
        $result = [];
        foreach ($names as $source => $target) {
            if (array_key_exists($source, $values)) {
                $result[$target] = $values[$source];
            }
        }

        return $record instanceof stdClass ? (object) $result : $result;
    }

    /**
     * Map records in a JSON list without changing list order.
     *
     * @param array<array-key, mixed>|callable(array<array-key, mixed>|stdClass): mixed $map
     *
     * @return list<mixed>
     */
    public static function many(mixed $items, array|callable $map): array
    {
        $mapper = self::mapper($map);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidUpstreamResponse('Expected an upstream list.');
        }
        $result = [];
        foreach ($items as $item) {
            $result[] = $mapper(self::record($item));
        }

        return $result;
    }

    /**
     * Place one mapped record in an application-owned envelope.
     *
     * @param array<array-key, mixed>|callable(array<array-key, mixed>|stdClass): mixed $map
     * @param array<array-key, mixed>                                                   $envelope
     * @param array<array-key, mixed>                                                   $from
     * @param array<array-key, mixed>                                                   $to
     */
    public static function item(
        mixed $json,
        array|callable $map,
        array $envelope = [],
        array $from = ['data'],
        array $to = ['data'],
    ): stdClass {
        $mapper = self::mapper($map);

        return self::put(
            (object) $envelope,
            self::destination($to),
            $mapper(self::record(self::read($json, $from))),
        );
    }

    /**
     * Place mapped records and an optional total in an application-owned envelope.
     *
     * @param array<array-key, mixed>|callable(array<array-key, mixed>|stdClass): mixed $map
     * @param array<array-key, mixed>                                                   $envelope
     * @param array<array-key, mixed>                                                   $from
     * @param array<array-key, mixed>                                                   $to
     * @param array<array-key, mixed>|null                                              $totalFrom
     * @param array<array-key, mixed>|null                                              $totalTo
     */
    public static function collection(
        mixed $json,
        array|callable $map,
        array $envelope = [],
        array $from = ['data'],
        array $to = ['data'],
        ?array $totalFrom = null,
        ?array $totalTo = null,
    ): stdClass {
        $totalPaths = match (true) {
            $totalFrom === null && $totalTo === null => null,
            $totalFrom === null || $totalTo === null => throw new InvalidArgumentException(
                'Totals require source and target paths.',
            ),
            default => [$totalFrom, $totalTo],
        };
        $destination = self::destination($to);
        $result = self::put(
            (object) $envelope,
            $destination,
            self::many(self::read($json, $from), $map),
        );
        if ($totalPaths !== null) {
            [$totalSource, $totalTarget] = $totalPaths;
            $totalDestination = self::destination($totalTarget);
            $length = min(count($destination), count($totalDestination));
            if (array_slice($destination, 0, $length)
                === array_slice($totalDestination, 0, $length)) {
                throw new InvalidArgumentException('Collection data and total paths overlap.');
            }
            $result = self::put(
                $result,
                $totalDestination,
                self::total(self::read($json, $totalSource)),
            );
        }

        return $result;
    }

    /**
     * Translate the flat filter, sort, with and offset conventions of a legacy list.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sorts
     * @param array<array-key, mixed> $includes
     *
     * @return array<array-key, mixed>|false
     */
    public static function legacyQuery(
        array $query,
        array $filters = [],
        array $sorts = [],
        array $includes = [],
        int $defaultLimit = 20,
        int $maxLimit = 100,
    ): array|false {
        $filterNames = self::names($filters);
        $sortNames = self::names($sorts);
        $includeNames = self::names($includes);
        if ($defaultLimit < 1 || $maxLimit < $defaultLimit) {
            throw new InvalidArgumentException('Pagination default must be within 1..maxLimit.');
        }
        if (! self::supportedQuery($query)) {
            return false;
        }
        $parts = [
            'filter'  => self::queryFilters($query['filter'] ?? [], $filterNames),
            'sort'    => self::querySort($query['sort'] ?? [], $sortNames),
            'include' => self::queryIncludes($query['with'] ?? [], $includeNames),
        ];
        if (in_array(false, $parts, true)) {
            return false;
        }
        $pagination = self::pagination($query, $defaultLimit, $maxLimit);
        if ($pagination === false) {
            return false;
        }

        return array_filter(
            $parts,
            static fn (array|string|false $part): bool => $part !== [] && $part !== '',
        ) + $pagination;
    }

    /**
     * Rename validation fields and collect unknown fields without changing messages.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function validationErrors(
        mixed $errors,
        array $fields,
        string $unknown = '_general',
    ): stdClass {
        $names = self::names($fields);
        $result = [];
        $record = self::record($errors);
        $messagesByField = $record instanceof stdClass ? get_object_vars($record) : $record;
        foreach ($messagesByField as $field => $messages) {
            if (! is_array($messages) || ! array_is_list($messages)) {
                throw new InvalidUpstreamResponse('Expected a list of validation messages.');
            }
            foreach ($messages as $message) {
                if (! is_string($message)) {
                    throw new InvalidUpstreamResponse('Expected a validation message string.');
                }
            }
            $target = $names[$field] ?? $unknown;
            $result[$target] = array_merge($result[$target] ?? [], $messages);
        }

        return (object) $result;
    }

    /**
     * Validate literal field names at the configuration boundary.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return array<array-key, string>
     */
    private static function names(array $fields): array
    {
        $result = [];
        foreach ($fields as $source => $target) {
            if (! is_string($target)) {
                throw new InvalidArgumentException('Field map targets must be strings.');
            }
            $result[$source] = $target;
        }

        return $result;
    }

    /**
     * Resolve a callback before interpreting an array as field names.
     *
     * @param array<array-key, mixed>|callable(array<array-key, mixed>|stdClass): mixed $map
     *
     * @return callable(array<array-key, mixed>|stdClass): mixed
     */
    private static function mapper(array|callable $map): callable
    {
        if (is_callable($map)) {
            return $map;
        }
        self::fields([], $map);

        return static fn (array|stdClass $record): array|stdClass => self::fields($record, $map);
    }

    /**
     * Require a decoded object or an associative PHP record.
     *
     * @return array<array-key, mixed>|stdClass
     */
    private static function record(mixed $value): array|stdClass
    {
        if ($value instanceof stdClass
            || (is_array($value) && ($value === [] || ! array_is_list($value)))) {
            return $value;
        }

        throw new InvalidUpstreamResponse('Expected an upstream record.');
    }

    /**
     * Validate path segments without giving dots a second meaning.
     *
     * @param array<array-key, mixed> $path
     *
     * @return list<array-key>
     */
    private static function path(array $path): array
    {
        if (! array_is_list($path)) {
            throw new InvalidArgumentException(
                'Use literal segment lists; destination paths must not be empty.',
            );
        }

        return array_map(static function (mixed $segment): string|int {
            if (! is_string($segment) && ! is_int($segment)) {
                throw new InvalidArgumentException('Path segments must be strings or integers.');
            }

            return array_key_first([$segment => null]);
        }, $path);
    }

    /**
     * Require a destination without replacing the whole response envelope.
     *
     * @param array<array-key, mixed> $path
     *
     * @return non-empty-list<array-key>
     */
    private static function destination(array $path): array
    {
        $segments = self::path($path);
        if ($segments === []) {
            throw new InvalidArgumentException(
                'Use literal segment lists; destination paths must not be empty.',
            );
        }

        return $segments;
    }

    /**
     * Read an explicitly segmented path and distinguish missing from null.
     *
     * @param array<array-key, mixed> $path
     */
    private static function read(mixed $value, array $path): mixed
    {
        $current = $value;
        foreach (self::path($path) as $segment) {
            if (! is_array($current) && ! $current instanceof stdClass) {
                throw new InvalidUpstreamResponse('Expected an upstream path container.');
            }
            $values = $current instanceof stdClass ? get_object_vars($current) : $current;
            if (! array_key_exists($segment, $values)) {
                throw new InvalidUpstreamResponse('Missing an upstream mapping path.');
            }
            $current = $values[$segment];
        }

        return $current;
    }

    /**
     * Build destination containers without mutating caller-owned objects.
     *
     * @param array<array-key, mixed>|stdClass $value
     * @param non-empty-list<array-key>        $path
     *
     * @return ($value is stdClass ? stdClass : array<array-key, mixed>)
     */
    private static function put(array|stdClass $value, array $path, mixed $data): array|stdClass
    {
        $key = array_shift($path);
        $values = $value instanceof stdClass ? get_object_vars($value) : $value;
        if ($path === []) {
            $values[$key] = $data;
        } else {
            $child = $values[$key] ?? new stdClass();
            if (! is_array($child) && ! $child instanceof stdClass) {
                throw new InvalidArgumentException('Destination paths require containers.');
            }
            $values[$key] = self::put($child, $path, $data);
        }

        return $value instanceof stdClass ? (object) $values : $values;
    }

    /**
     * Require an exact nonnegative integer total without losing precision.
     */
    private static function total(mixed $value): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidUpstreamResponse('Expected a nonnegative upstream total.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0 || (string) $integer !== (string) $value) {
            throw new InvalidUpstreamResponse('Expected a nonnegative upstream total.');
        }

        return $integer;
    }

    /**
     * Retain legacy requests whose extra projection cannot be translated.
     *
     * @param array<array-key, mixed> $query
     */
    private static function supportedQuery(array $query): bool
    {
        $supported = [
            'r',
            'filter',
            'sort',
            'with',
            'start',
            'limit',
            'fields',
            'without',
            'merge_with',
            'data',
        ];

        return ! array_diff(array_keys($query), $supported)
            && empty($query['fields']) && empty($query['without']) && empty($query['merge_with']);
    }

    /**
     * Translate filters while rejecting aliases that would overwrite one another.
     *
     * @param array<array-key, string> $names
     *
     * @return array<string, string|int|float|bool>|false
     */
    private static function queryFilters(mixed $values, array $names): array|false
    {
        if (! is_array($values)) {
            return false;
        }
        $result = [];
        foreach ($values as $field => $value) {
            if (! array_key_exists($field, $names) || array_key_exists($names[$field], $result)) {
                return false;
            }
            $mapped = self::filterValue($value);
            if ($mapped === null) {
                return false;
            }
            $result[$names[$field]] = $mapped;
        }

        return $result;
    }

    /**
     * Translate configured sort directions into a comma-separated ordering.
     *
     * @param array<array-key, string> $names
     */
    private static function querySort(mixed $values, array $names): string|false
    {
        if (! is_array($values)) {
            return false;
        }
        $result = [];
        foreach ($values as $field => $direction) {
            if (! array_key_exists($field, $names) || ! is_string($direction)
                || ! in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
                return false;
            }
            $result[] = (strtoupper($direction) === 'DESC' ? '-' : '').$names[$field];
        }

        return implode(',', $result);
    }

    /**
     * Translate relation names without repeating aliases or implicit includes.
     *
     * @param array<array-key, string> $names
     */
    private static function queryIncludes(mixed $values, array $names): string|false
    {
        if (! is_array($values)) {
            return false;
        }
        $result = [];
        foreach ($values as $relation) {
            if (! is_string($relation) || ! array_key_exists($relation, $names)) {
                return false;
            }
            if ($names[$relation] !== '') {
                $result[] = $names[$relation];
            }
        }

        return implode(',', array_unique($result));
    }

    /**
     * Translate exact offset pages without rounding or integer overflow.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array{page: int, per_page: int}|false
     */
    private static function pagination(array $query, int $defaultLimit, int $maxLimit): array|false
    {
        $limit = self::pageLimit($query['limit'] ?? $defaultLimit, $maxLimit);
        $start = filter_var($query['start'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $start === false || $start < 0 || $start % $limit !== 0) {
            return false;
        }
        $page = intdiv($start, $limit);
        if ($page === PHP_INT_MAX) {
            return false;
        }

        return ['page' => $page + 1, 'per_page' => $limit];
    }

    /**
     * Parse a positive page size within the configured upstream maximum.
     */
    private static function pageLimit(mixed $value, int $maximum): int|false
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);

        return $limit !== false && $limit >= 1 && $limit <= $maximum ? $limit : false;
    }

    /**
     * Convert scalar filter lists to CSV, returning null for unsupported values.
     */
    private static function filterValue(mixed $value): string|int|float|bool|null
    {
        if (is_scalar($value)) {
            return $value;
        }
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_scalar($item) || trim((string) $item) === '') {
                return null;
            }
            $items[] = $item;
        }

        return implode(',', $items);
    }
}
