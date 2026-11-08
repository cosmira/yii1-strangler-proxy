<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Schema;

/** One upstream-to-legacy naming schema, shared by records, writes and queries. */
final readonly class Fields
{
    /**
     * Keep response names separate from directional input aliases.
     *
     * @param array<string, string> $names
     * @param array<string, string> $filterAliases
     * @param array<string, string> $sortAliases
     * @param list<string>|null     $selection     Null selects every record field.
     */
    private function __construct(
        private array $names,
        private array $filterAliases = [],
        private array $sortAliases = [],
        private ?array $selection = [],
    ) {}

    /**
     * Convert every record field by convention, or select an explicit list of names.
     *
     * @param array<array-key, string>|null $fields
     */
    public static function uppercase(?array $fields = null): self
    {
        if ($fields === null) {
            return new self([], selection: null);
        }

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
     * Override conventional names without enumerating ordinary fields.
     *
     * @param array<string, string> $names
     */
    public function rename(array $names): self
    {
        foreach (array_keys($names) as $field) {
            $this->legacyName($field);
        }

        return new self(
            array_replace($this->names, $names),
            $this->filterAliases,
            $this->sortAliases,
            $this->selection,
        );
    }

    /**
     * Resolve automatic names from a record; explicit selections keep their existing order.
     *
     * @param array<array-key, mixed>|\stdClass $record
     *
     * @return array<array-key, string>
     */
    public function namesFor(array|\stdClass $record): array
    {
        if ($this->selection !== null) {
            return $this->names;
        }

        $names = [];
        foreach (array_keys((array) $record) as $field) {
            $names[$field] = $this->legacyName((string) $field);
        }

        return $names;
    }

    /**
     * Select fields without redefining their names.
     *
     * @param array<array-key, string> $fields
     */
    public function only(array $fields): self
    {
        $names = [];
        foreach ($fields as $field) {
            $names[$field] = $this->legacyName($field);
        }

        return new self(
            $names, $this->filterAliases, $this->sortAliases,
        );
    }

    /**
     * Resolve a convention name, rejecting fields outside an explicit selection.
     */
    public function legacyName(string $field): string
    {
        if ($this->selection !== null && ! array_key_exists($field, $this->names)) {
            throw new \InvalidArgumentException("Unknown field: {$field}");
        }

        return $this->names[$field] ?? strtoupper($field);
    }

    /**
     * Use a different upstream name when this field is filtered.
     */
    public function filterAs(string $field, string $name): self
    {
        $this->legacyName($field);

        return new self(
            $this->names,
            [...$this->filterAliases, $field => $name],
            $this->sortAliases,
            $this->selection,
        );
    }

    /**
     * Use a different upstream name when this field is sorted.
     */
    public function sortAs(string $field, string $name): self
    {
        $this->legacyName($field);

        return new self(
            $this->names,
            $this->filterAliases,
            [...$this->sortAliases, $field => $name],
            $this->selection,
        );
    }

    /**
     * Return selected legacy filter names mapped to upstream names.
     *
     * @param list<array-key>|null $legacyNames
     *
     * @return array<array-key, string>
     */
    public function filters(?array $legacyNames = null): array
    {
        return $this->aliasedInverse($this->filterAliases, $legacyNames);
    }

    /**
     * Return selected legacy sort names mapped to upstream names.
     *
     * @param list<array-key>|null $legacyNames
     *
     * @return array<array-key, string>
     */
    public function sorts(?array $legacyNames = null): array
    {
        return $this->aliasedInverse($this->sortAliases, $legacyNames);
    }

    /**
     * Return selected writable names mapped to upstream names.
     *
     * @param list<array-key>|null $legacyNames
     *
     * @return array<array-key, string>
     */
    public function writes(?array $legacyNames = null): array
    {
        return $this->inverse($legacyNames);
    }

    /**
     * Apply directional aliases without changing response names.
     *
     * @param array<string, string> $aliases
     * @param list<array-key>|null  $legacyNames
     *
     * @return array<array-key, string>
     */
    private function aliasedInverse(array $aliases, ?array $legacyNames = null): array
    {
        return array_map(
            static fn (string $name): string => $aliases[$name] ?? $name,
            $this->inverse($legacyNames),
        );
    }

    /**
     * Return declared response names; automatic fields resolve against each record.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * Reject ambiguous target names before reversing the selected map.
     *
     * @param list<array-key>|null $legacyNames
     *
     * @return array<array-key, string>
     */
    public function inverse(?array $legacyNames = null): array
    {
        if (count(array_unique($this->names)) !== count($this->names)) {
            throw new \InvalidArgumentException('Field maps require distinct target names.');
        }

        $declared = array_flip($this->names);
        if ($legacyNames === null) {
            return $declared;
        }

        $resolved = [];
        foreach ($legacyNames as $legacy) {
            if (isset($declared[$legacy])) {
                $resolved[$legacy] = $declared[$legacy];
            } elseif ($this->selection === null) {
                $resolved[$legacy] = strtolower((string) $legacy);
            } else {
                throw new \InvalidArgumentException('Unknown legacy field: '.$legacy);
            }
        }

        return $resolved;
    }
}
