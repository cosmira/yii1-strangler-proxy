<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Schema;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use DateTimeImmutable;
use ValueError;

/** Reusable value conversions for declared request and response differences. */
final class Format
{
    /**
     * Normalize Yii boolean values while retaining invalid input for validation.
     */
    public static function boolean(mixed $value): mixed
    {
        return in_array($value, [0, 1, '0', '1', true, false], true) ? (bool) $value : $value;
    }

    /**
     * Decode JSON strings without changing already decoded values.
     */
    public static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    /**
     * Encode arrays and JSON objects for an API that stores JSON as a string.
     */
    public static function jsonString(mixed $value): mixed
    {
        return is_array($value) || $value instanceof \stdClass ? json_encode($value) : $value;
    }

    /**
     * Bind a filter alias vocabulary once for reuse across requests.
     *
     * @param array<string, list<string>> $aliases
     *
     * @return \Closure(mixed): mixed
     */
    public static function expandWith(array $aliases): \Closure
    {
        return static fn (mixed $value): mixed => self::expand($value, $aliases);
    }

    /**
     * Convert Unix timestamp filters to UTC while retaining invalid values for validation.
     */
    public static function unixTimestamp(mixed $value): mixed
    {
        return is_numeric($value) ? gmdate('Y-m-d\\TH:i:s\\Z', (int) $value) : $value;
    }

    /**
     * Expand normalized CSV filter values while retaining unknown names and request order.
     *
     * @param array<string, list<string>> $aliases
     */
    public static function expand(mixed $value, array $aliases): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $expanded = [];
        foreach (explode(',', $value) as $name) {
            $expanded[] = $name;
            array_push($expanded, ...($aliases[$name] ?? []));
        }

        return implode(',', array_unique($expanded));
    }

    /**
     * Render a JSON ISO 8601 timestamp without silently normalizing an invalid calendar date.
     */
    public static function timestamp(mixed $value, string $format): string
    {
        if (! is_string($value)) {
            throw new InvalidUpstreamResponse('Expected a timestamp string.');
        }
        $source = str_contains($value, '.') ? '!Y-m-d\\TH:i:s.uP' : '!Y-m-d\\TH:i:sP';

        try {
            $date = DateTimeImmutable::createFromFormat($source, $value);
        } catch (ValueError $exception) {
            throw new InvalidUpstreamResponse('Invalid upstream timestamp.', previous: $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        $invalid = $errors !== false && ($errors['warning_count'] || $errors['error_count']);
        if ($date === false || $invalid) {
            throw new InvalidUpstreamResponse('Invalid upstream timestamp.');
        }

        return $date->format($format);
    }
}
