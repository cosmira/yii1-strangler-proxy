<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Internal\Payload;

use InvalidArgumentException;
use stdClass;

/**
 * Move declared body values into route parameters without consuming neighboring data.
 *
 * @internal
 */
final class BodyParameters
{
    /**
     * Reject invalid bindings while the filter configuration is built.
     *
     * @param array<array-key, mixed> $bindings
     */
    public static function validate(array $bindings, string $route): void
    {
        foreach ($bindings as $name => $sources) {
            if (! is_string($name) || ! str_contains($route, '{'.$name.'}')) {
                throw new InvalidArgumentException(
                    'Body bindings require a matching route placeholder.',
                );
            }
            self::validateSources($sources);
        }
    }

    /**
     * Require a nonempty list of nonempty dotted source paths.
     */
    private static function validateSources(mixed $sources): void
    {
        $paths = is_string($sources) ? [$sources] : $sources;
        if (! is_array($paths) || $paths === [] || ! array_is_list($paths)) {
            throw new InvalidArgumentException('Body bindings require one or more source paths.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || in_array('', explode('.', $path), true)) {
                throw new InvalidArgumentException('Body source paths require nonempty segments.');
            }
        }
    }

    /**
     * Retain JSON object/list shape when the passthrough request has a raw body.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>|stdClass
     */
    public static function source(array $payload, ?string $body): array|stdClass
    {
        if ($body === null || trim($body) === '') {
            return $payload;
        }
        $value = json_decode($body, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($value) && ! $value instanceof stdClass) {
            throw new InvalidArgumentException('A body binding requires an object or array.');
        }

        return $value;
    }

    /**
     * Resolve all parameters against the original body before consuming any values.
     *
     * @param array<string, string|list<string>> $bindings
     * @param array<array-key, mixed>|stdClass   $payload
     *
     * @return array{
     *     parameters: array<string, int|string>, payload: array<array-key, mixed>|stdClass
     * }|false
     */
    public static function resolve(array $bindings, array|stdClass $payload): array|false
    {
        $parameters = [];
        $consumed = [];

        try {
            foreach ($bindings as $name => $sources) {
                $binding = self::binding($sources, $payload);
                if ($binding === false) {
                    return false;
                }
                $parameters[$name] = $binding['value'];
                array_push($consumed, ...$binding['paths']);
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        $remaining = $payload;
        foreach ($consumed as $path) {
            $remaining = self::remove($remaining, $path);
        }

        return ['parameters' => $parameters, 'payload' => self::restoreShape($remaining, $payload)];
    }

    /**
     * Resolve alternative sources to one identifier without consuming their paths.
     *
     * @param string|list<string>              $sources
     * @param array<array-key, mixed>|stdClass $payload
     *
     * @return array{value: int|string, paths: list<list<array-key>>}|false
     */
    private static function binding(string|array $sources, array|stdClass $payload): array|false
    {
        $identifier = null;
        $paths = [];
        foreach (is_string($sources) ? [$sources] : $sources as $source) {
            $found = self::read($payload, explode('.', $source));
            if ($found === null) {
                continue;
            }
            if ($identifier !== null && (string) $found['value'] !== (string) $identifier) {
                return false;
            }
            $identifier ??= $found['value'];
            $paths[] = $found['path'];
        }

        return $identifier === null ? false : ['value' => $identifier, 'paths' => $paths];
    }

    /**
     * Treat a wildcard as exactly one list element, never as a first-item shortcut.
     *
     * @param list<string> $segments
     *
     * @return array{value: int|string, path: list<array-key>}|null
     */
    private static function read(mixed $payload, array $segments): ?array
    {
        $value = $payload;
        $path = [];
        foreach ($segments as $segment) {
            $segment = self::segment($value, $segment);
            $members = (array) $value;
            if (! array_key_exists($segment, $members)) {
                return null;
            }
            $value = $members[$segment];
            $path[] = $segment;
        }
        if ((! is_string($value) && ! is_int($value))
            || in_array((string) $value, ['', '.', '..'], true)) {
            throw new InvalidArgumentException('A route parameter requires a nonempty identifier.');
        }

        return ['value' => $value, 'path' => $path];
    }

    /**
     * Resolve a wildcard and require a traversable JSON container.
     */
    private static function segment(mixed $value, string $segment): int|string
    {
        if ($segment === '*') {
            if (! is_array($value) || ! array_is_list($value) || count($value) !== 1) {
                throw new InvalidArgumentException(
                    'A body wildcard requires exactly one list item.',
                );
            }

            return 0;
        }
        if (! $value instanceof stdClass && ! is_array($value)) {
            throw new InvalidArgumentException('A body path requires an object or array.');
        }

        return $segment;
    }

    /**
     * Delete only the selected leaf and ancestors made empty by that deletion.
     *
     * @param array<array-key, mixed>|stdClass $node
     * @param list<array-key>                  $path
     *
     * @return array<array-key, mixed>|stdClass
     */
    private static function remove(array|stdClass $node, array $path): array|stdClass
    {
        $values = (array) $node;
        assert($path !== []);
        $key = array_shift($path);
        if ($path === []) {
            unset($values[$key]);
        } elseif (isset($values[$key])) {
            $member = $values[$key];
            assert(is_array($member) || $member instanceof stdClass);
            $child = self::remove($member, $path);
            if ((array) $child === []) {
                unset($values[$key]);
            } else {
                $values[$key] = $child;
            }
        }

        return $node instanceof stdClass ? (object) $values : $values;
    }

    /**
     * Reindex original lists only after all paths have been consumed, preserving object keys.
     *
     * @return ($value is stdClass ? stdClass : ($value is array ? array<array-key, mixed> : mixed))
     */
    private static function restoreShape(mixed $value, mixed $original): mixed
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            return $value;
        }
        $values = (array) $value;
        foreach ($values as $key => $child) {
            $values[$key] = self::restoreShape($child, ((array) $original)[$key] ?? null);
        }

        if ($value instanceof stdClass) {
            return (object) $values;
        }

        return is_array($original) && array_is_list($original) ? array_values($values) : $values;
    }
}
