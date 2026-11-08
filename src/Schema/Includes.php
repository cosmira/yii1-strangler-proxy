<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Schema;

use InvalidArgumentException;

/** Normalizes include trees with optional application-owned relation restrictions. */
final class Includes
{
    /**
     * Compiled child schemas indexed by native relation names.
     *
     * @var array<string, self>|null
     */
    private ?array $allowed = [];

    /**
     * Compile the supported relation tree and reject malformed configuration.
     *
     * @param array<array-key, mixed>|null $relations
     */
    private function __construct(?array $relations)
    {
        if ($relations === null) {
            $this->allowed = null;

            return;
        }
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
     * Accept every well-formed relation tree without imposing backend capabilities.
     */
    public static function all(): self
    {
        return new self(null);
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
            if (! is_string($name)) {
                return null;
            }
            $schema = $this->relationSchema($name);
            if ($schema === null) {
                return null;
            }

            $nested = $schema->normalize($children);
            if ($nested === null) {
                return null;
            }

            $normalized[$name] = $nested;
        }

        return $normalized;
    }

    /**
     * Resolve known relations or reuse the unrestricted schema at every nested depth.
     */
    private function relationSchema(string $name): ?self
    {
        if ($name === '') {
            return null;
        }

        return $this->allowed === null ? $this : ($this->allowed[$name] ?? null);
    }
}
