<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Closure;
use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;
use stdClass;

/** Maps a record graph without owning request or response transport. */
final class Record
{
    /**
     * Source identifier required before mapping a record.
     */
    private ?string $identifier = null;
    /**
     * Upstream metadata omitted from the legacy representation.
     *
     * @var list<string>
     */
    private array $excluded = [];
    /**
     * Source attributes gated by their native projection keys.
     *
     * @var list<string>
     */
    private array $optional = [];
    /**
     * Related schemas, native names and collection cardinalities.
     *
     * @var array<string, array{self|Closure(): self, string, bool}>
     */
    private array $relations = [];
    /**
     * Root selection resolver; child records receive an inherited selection.
     *
     * @var Closure(array<array-key, mixed>): ?array<string, mixed>|null
     */
    private ?Closure $projection = null;
    /**
     * Ordered application value normalizers before field and relation mapping.
     *
     * @var list<Closure(array<array-key, mixed>, ?array<string, mixed>): array<array-key, mixed>>
     */
    private array $normalizers = [];
    /**
     * Ordered derived-field transforms after naming and related-record mapping.
     *
     * @var list<Closure(array<array-key, mixed>,
     *     array<array-key, mixed>, ?array<string, mixed>): array<array-key, mixed>>
     */
    private array $finalizers = [];

    /**
     * Retain one naming schema for attributes and optional projection fields.
     *
     * @param Fields $fields Shared names for attributes and optional projection selection.
     */
    private function __construct(private Fields $fields) {}

    /**
     * Map ordinary attributes by convention without enumerating them.
     */
    public static function uppercase(): self
    {
        return new self(Fields::uppercase());
    }

    /**
     * Use an existing field selection or automatic naming schema.
     */
    public static function fields(Fields $fields): self
    {
        return new self($fields);
    }

    /**
     * Expose the naming schema shared with writable fields and queries.
     */
    public function schema(): Fields
    {
        return $this->fields;
    }

    /**
     * Require a scalar identifier and declare its legacy name.
     */
    public function identifier(string $nativeName, string $source = 'id'): self
    {
        $copy = $this->rename([$source => $nativeName]);
        $copy->identifier = $source;

        return $copy;
    }

    /**
     * Override attribute names while preserving relation declarations.
     *
     * @param array<string, string> $names
     */
    public function rename(array $names): self
    {
        $copy = clone $this;
        $copy->fields = $this->fields->rename($names);

        return $copy;
    }

    /**
     * Omit upstream metadata that is not part of the legacy contract.
     *
     * @param list<string> $fields
     */
    public function except(array $fields): self
    {
        $copy = clone $this;
        $copy->excluded = $fields;

        return $copy;
    }

    /**
     * Emit these attributes only when their native projection key is requested.
     *
     * @param list<string> $fields
     */
    public function optional(array $fields): self
    {
        $copy = clone $this;
        $copy->optional = $fields;

        return $copy;
    }

    /**
     * Map a related list in order, building lazy schemas only when needed.
     *
     * @param self|callable(): self $record
     */
    public function many(string $source, self|callable $record, ?string $as = null): self
    {
        return $this->relation($source, $record, $as, true);
    }

    /**
     * Map a nullable related object under its native relation name.
     *
     * @param self|callable(): self $record
     */
    public function one(string $source, self|callable $record, ?string $as = null): self
    {
        return $this->relation($source, $record, $as, false);
    }

    /**
     * Keep relation cardinality separate from attribute naming.
     *
     * @param self|callable(): self $record
     */
    private function relation(string $source, self|callable $record, ?string $as, bool $many): self
    {
        $copy = clone $this;
        $copy->relations[$source] = [
            $record instanceof self ? $record : Closure::fromCallable($record),
            $as ?? $source,
            $many,
        ];

        return $copy;
    }

    /**
     * Resolve the root selection from the original request context.
     *
     * @param callable(array<array-key, mixed>): ?array<string, mixed> $resolver
     */
    public function projectionUsing(callable $resolver): self
    {
        $copy = clone $this;
        $copy->projection = Closure::fromCallable($resolver);

        return $copy;
    }

    /**
     * Append application value normalization before naming and relation traversal.
     *
     * @param callable(array<array-key, mixed>,
     *     ?array<string, mixed>): array<array-key, mixed> $mapper
     */
    public function attributesUsing(callable $mapper): self
    {
        $copy = clone $this;
        $copy->normalizers[] = Closure::fromCallable($mapper);

        return $copy;
    }

    /**
     * Append derived-field transforms after the related records have been mapped.
     *
     * @param callable(array<array-key, mixed>,
     *     array<array-key, mixed>, ?array<string, mixed>): array<array-key, mixed> $mapper
     */
    public function afterUsing(callable $mapper): self
    {
        $copy = clone $this;
        $copy->finalizers[] = Closure::fromCallable($mapper);

        return $copy;
    }

    /**
     * Map a root record while preserving its array or JSON-object shape.
     *
     * @param array<array-key, mixed>|stdClass $record
     * @param array<array-key, mixed>          $context
     *
     * @return array<array-key, mixed>|stdClass
     */
    public function __invoke(array|stdClass $record, array $context = []): array|stdClass
    {
        $projection = $this->projection === null ? null : ($this->projection)($context);

        return $this->map($record, $context, $projection);
    }

    /**
     * Apply the inherited selection without rerunning the root projection resolver.
     *
     * @param array<array-key, mixed>|stdClass $record
     * @param array<array-key, mixed>          $context
     * @param array<string, mixed>|null        $projection
     *
     * @return array<array-key, mixed>|stdClass
     */
    private function map(array|stdClass $record, array $context, ?array $projection): array|stdClass
    {
        $raw = $this->validateRecord($record);
        $attributes = $raw;
        foreach ($this->normalizers as $normalize) {
            $attributes = $normalize($attributes, $projection);
        }

        try {
            $mapped = Mapping::fields(
                $this->selectedAttributes($attributes, $projection), $this->fields,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidUpstreamResponse(
                'Ambiguous upstream record fields.', previous: $exception,
            );
        }
        $mapped = $this->mapRelations($mapped, $attributes, $context, $projection);
        foreach ($this->finalizers as $finalize) {
            $mapped = $finalize($mapped, $raw, $projection);
        }

        return $record instanceof stdClass ? (object) $mapped : $mapped;
    }

    /**
     * Reject malformed root objects and identifiers before running application callbacks.
     *
     * @param array<array-key, mixed>|stdClass $record
     *
     * @return array<array-key, mixed>
     */
    private function validateRecord(array|stdClass $record): array
    {
        if (is_array($record) && array_is_list($record) && $record !== []) {
            throw new InvalidUpstreamResponse('Expected a record object.');
        }

        $raw = (array) $record;
        if ($this->identifier !== null) {
            $id = $raw[$this->identifier] ?? null;
            if (! is_string($id) && ! is_int($id)) {
                throw new InvalidUpstreamResponse('Missing record identifier.');
            }
        }

        return $raw;
    }

    /**
     * Remove metadata, related values and attributes absent from the requested projection.
     *
     * @param array<array-key, mixed>   $attributes
     * @param array<string, mixed>|null $projection
     *
     * @return array<array-key, mixed>
     */
    private function selectedAttributes(array $attributes, ?array $projection): array
    {
        $selected = array_diff_key($attributes, array_flip($this->excluded), $this->relations);
        foreach ($this->optional as $source) {
            if ($projection !== null
                && ! array_key_exists($this->fields->legacyName($source), $projection)) {
                unset($selected[$source]);
            }
        }

        return $selected;
    }

    /**
     * Traverse only present, selected relations and preserve their declared cardinality.
     *
     * @param array<array-key, mixed>   $mapped
     * @param array<array-key, mixed>   $attributes
     * @param array<array-key, mixed>   $context
     * @param array<string, mixed>|null $projection
     *
     * @return array<array-key, mixed>
     */
    private function mapRelations(
        array $mapped, array $attributes, array $context, ?array $projection,
    ): array {
        $present = array_intersect_key($this->relations, $attributes);
        foreach ($present as $source => [$child, $name, $many]) {
            if ($projection !== null && ! array_key_exists($name, $projection)) {
                continue;
            }

            if (array_key_exists($name, $mapped)) {
                throw new InvalidUpstreamResponse('Ambiguous upstream relation names.');
            }

            $nested = $this->relationProjection($projection, $name);
            $value = $attributes[$source];
            $mapped[$name] = $many
                ? $this->mapCollection($child, $value, $context, $nested)
                : $this->mapOne($child, $value, $context, $nested);
        }

        return $mapped;
    }

    /**
     * Reject malformed nested selections before passing them to a child record.
     *
     * @param array<string, mixed>|null $projection
     *
     * @return array<string, mixed>|null
     */
    private function relationProjection(?array $projection, string $name): ?array
    {
        $nested = $projection[$name] ?? null;
        if ($nested === null) {
            return null;
        }
        if (! is_array($nested)) {
            throw new InvalidUpstreamResponse('Invalid record projection.');
        }
        $selection = [];
        foreach ($nested as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidUpstreamResponse('Invalid record projection.');
            }
            $selection[$key] = $value;
        }

        return $selection;
    }

    /**
     * Reject object-shaped collections and map each item without changing order.
     *
     * @param self|Closure(): self      $child
     * @param array<array-key, mixed>   $context
     * @param array<string, mixed>|null $projection
     *
     * @return list<array<array-key, mixed>|stdClass>
     */
    private function mapCollection(
        self|Closure $child, mixed $value, array $context, ?array $projection,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidUpstreamResponse('Expected a related record list.');
        }

        if ($value === []) {
            return [];
        }
        $schema = $child instanceof self ? $child : $child();

        return array_map(
            fn (mixed $item): array|stdClass => $this->mapChild(
                $schema, $item, $context, $projection,
            ),
            $value,
        );
    }

    /**
     * Preserve null without constructing a child schema; validate every present object.
     *
     * @param self|Closure(): self      $child
     * @param array<array-key, mixed>   $context
     * @param array<string, mixed>|null $projection
     *
     * @return array<array-key, mixed>|stdClass|null
     */
    private function mapOne(
        self|Closure $child, mixed $item, array $context, ?array $projection,
    ): array|stdClass|null {
        if ($item === null) {
            return null;
        }
        $schema = $child instanceof self ? $child : $child();

        return $this->mapChild($schema, $item, $context, $projection);
    }

    /**
     * Reject malformed related values before traversing their schema.
     *
     * @param array<array-key, mixed>   $context
     * @param array<string, mixed>|null $projection
     *
     * @return array<array-key, mixed>|stdClass
     */
    private function mapChild(
        self $child, mixed $item, array $context, ?array $projection,
    ): array|stdClass {
        if (! is_array($item) && ! $item instanceof stdClass) {
            throw new InvalidUpstreamResponse('Expected a related record object.');
        }

        return $child->map($item, $context, $projection);
    }
}
