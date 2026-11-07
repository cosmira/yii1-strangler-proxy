<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Schema;

use InvalidArgumentException;

/** Validates legacy list/object include trees against the supported relation graph. */
final class Includes
{
    /**
     * Compiled child schemas indexed by native relation names.
     *
     * @var array<string, self>
     */
    private array $allowed = [];

    /**
     * Compile the supported relation tree and reject malformed configuration.
     *
     * @param array<array-key, mixed> $relations
     */
    private function __construct(array $relations)
    {
        foreach ($relations as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $children = is_int($key) ? [] : $value;
            if (! is_string($name) || ! is_array($children)) {
                throw new InvalidArgumentException(
                    'Include schemas require relation names and nested arrays.',
                );
            }

            $this->allowed[$name] = new self($children);
        }
    }

    /**
     * Declare allowed relations and the supported depth of each branch.
     *
     * @param array<array-key, mixed> $relations
     */
    public static function only(array $relations): self
    {
        return new self($relations);
    }

    /**
     * Return a canonical include tree, or null for unsupported input.
     *
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $relations): ?array
    {
        if (! is_array($relations)) {
            return null;
        }

        $normalized = [];
        foreach ($relations as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $children = is_int($key) ? [] : $value;
            if (! is_string($name) || ! isset($this->allowed[$name])) {
                return null;
            }

            $nested = $this->allowed[$name]->normalize($children);
            if ($nested === null) {
                return null;
            }

            $normalized[$name] = $nested;
        }

        return $normalized;
    }
}
