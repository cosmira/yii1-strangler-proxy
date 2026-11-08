<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Closure;

/**
 * Compile shared record conventions once, resolving recursive relations lazily.
 *
 * @internal
 *
 * @phpstan-type Relation array{identifier: string, name: string, many: bool}
 * @phpstan-type AttributeMapper callable(
 *     array<array-key, mixed>, string, ?string
 * ): array<array-key, mixed>
 * @phpstan-type Finalizer callable(
 *     array<array-key, mixed>, array<array-key, mixed>, ?array<string, mixed>, string, ?string
 * ): array<array-key, mixed>
 * @phpstan-type Projection callable(array<array-key, mixed>): ?array<string, mixed>
 */
final class ResponseGraph
{
    /**
     * Resource-specific naming differences, independent of graph traversal.
     *
     * @var (Closure(string): array<string, string>)|null
     */
    private readonly ?Closure $fieldsUsing;

    /**
     * Cached immutable schemas, keyed by resource identifier and relation role.
     *
     * @var array<string, Record>
     */
    private array $records = [];

    /**
     * Keep application differences independent of graph traversal and schema lifetime.
     *
     * @param array<string, string>                          $fields
     * @param array<string, callable(mixed): mixed>          $values
     * @param array<string, Relation>                        $relations
     * @param list<string>                                   $except
     * @param list<string>                                   $optional
     * @param list<AttributeMapper>                          $attributes
     * @param list<Finalizer>                                $after
     * @param (callable(string): array<string, string>)|null $fieldsUsing
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $values,
        private readonly array $relations,
        private readonly array $except,
        private readonly array $optional,
        private readonly array $attributes,
        private readonly array $after,
        ?callable $fieldsUsing = null,
    ) {
        $this->fieldsUsing = $fieldsUsing === null ? null : $fieldsUsing(...);
    }

    /**
     * Reuse immutable schemas; requested projections remain local to each traversal.
     */
    public function record(string $identifier, ?string $relation = null): Record
    {
        $key = $identifier."\0".$relation;
        if (isset($this->records[$key])) {
            return $this->records[$key];
        }

        $differences = $this->fieldsUsing === null ? [] : ($this->fieldsUsing)($identifier);
        $fields = $relation === null
            ? array_replace($differences, $this->fields)
            : array_replace($this->fields, $differences);
        if ($relation !== null) {
            unset($fields['id']);
        }
        $record = Record::uppercase()->identifier($identifier)->rename($fields)
            ->except($this->except)
            ->optional($this->optional)
            ->attributesUsing(function (array $attributes) use ($identifier, $relation): array {
                $values = $attributes;
                foreach ($this->values as $field => $format) {
                    if (array_key_exists($field, $values)) {
                        $values[$field] = $format($values[$field]);
                    }
                }
                foreach ($this->attributes as $map) {
                    $values = $map($values, $identifier, $relation);
                }

                return $values;
            });
        $record->schema()->inverse();
        foreach ($this->after as $finalize) {
            $record = $record->afterUsing(
                static fn (array $mapped, array $raw, ?array $projection): array => $finalize(
                    $mapped, $raw, $projection, $identifier, $relation,
                ),
            );
        }
        foreach ($this->relations as $source => $child) {
            $schema = fn (): Record => $this->record($child['identifier'], $source);
            $record = $child['many']
                ? $record->many($source, $schema, $child['name'])
                : $record->one($source, $schema, $child['name']);
        }

        return $this->records[$key] = $record;
    }
}
