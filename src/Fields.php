<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

/** One upstream-to-legacy naming schema, shared by records, writes and queries. */
final readonly class Fields
{
    /**
     * @param array<string, string> $names
     * @param array<string, string> $filterAliases
     * @param array<string, string> $sortAliases
     * @param array<string, string> $writeAliases
     */
    private function __construct(
        private array $names,
        private array $filterAliases = [],
        private array $sortAliases = [],
        private array $writeAliases = [],
    ) {}

    /**
     * Uppercase ordinary names; associative entries describe exceptions.
     *
     * @param array<array-key, string> $fields
     */
    public static function uppercase(array $fields): self
    {
        $names = [];
        foreach ($fields as $source => $target) {
            if (is_int($source)) {
                $source = $target;
                $target = strtoupper($target);
            }
            $names[$source] = $target;
        }

        return new self($names);
    }

    /**
     * Select fields without redefining their names.
     *
     * @param list<string> $fields
     */
    public function only(array $fields): self
    {
        $names = [];
        foreach ($fields as $field) {
            $names[$field] = $this->legacyName($field);
        }

        return new self($names, $this->filterAliases, $this->sortAliases, $this->writeAliases);
    }

    public function legacyName(string $field): string
    {
        if (! array_key_exists($field, $this->names)) {
            throw new \InvalidArgumentException("Unknown field: {$field}");
        }

        return $this->names[$field];
    }

    public function filterAs(string $field, string $name): self
    {
        $this->legacyName($field);

        return new self(
            $this->names,
            [...$this->filterAliases, $field => $name],
            $this->sortAliases,
            $this->writeAliases,
        );
    }

    public function sortAs(string $field, string $name): self
    {
        $this->legacyName($field);

        return new self(
            $this->names,
            $this->filterAliases,
            [...$this->sortAliases, $field => $name],
            $this->writeAliases,
        );
    }

    public function writeAs(string $field, string $name): self
    {
        $this->legacyName($field);

        return new self(
            $this->names,
            $this->filterAliases,
            $this->sortAliases,
            [...$this->writeAliases, $field => $name],
        );
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return $this->aliasedInverse($this->filterAliases);
    }

    /** @return array<string, string> */
    public function sorts(): array
    {
        return $this->aliasedInverse($this->sortAliases);
    }

    /** @return array<string, string> */
    public function writes(): array
    {
        return $this->aliasedInverse($this->writeAliases);
    }

    /**
     * @param array<string, string> $aliases
     *
     * @return array<string, string>
     */
    private function aliasedInverse(array $aliases): array
    {
        return array_map(static fn (string $name): string => $aliases[$name] ?? $name, $this->inverse());
    }

    /** @return array<string, string> */
    public function names(): array
    {
        return $this->names;
    }

    /** @return array<string, string> */
    public function inverse(): array
    {
        if (count(array_unique($this->names)) !== count($this->names)) {
            throw new \InvalidArgumentException('Field maps require distinct target names.');
        }

        return array_flip($this->names);
    }
}
