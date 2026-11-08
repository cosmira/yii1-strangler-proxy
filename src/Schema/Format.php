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
