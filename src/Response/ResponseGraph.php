<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Closure;

/**
 * Compile shared record conventions once, resolving recursive relations lazily.
 *
 * @internal
 *
 * @phpstan-type RelationOptions array{
 *     name?: string, many?: bool, identifier?: string, controller?: class-string,
 *     fields?: array<string, string>, except?: list<string>,
 *     attributes?: callable(array<array-key, mixed>): array<array-key, mixed>,
 *     after?: callable(array<array-key, mixed>, array<array-key, mixed>,
 *         ?array<string, mixed>): array<array-key, mixed>
 * }
 * @phpstan-type Relation class-string|RelationOptions
 * @phpstan-type AttributeMapper callable(
 *     array<array-key, mixed>, string, ?string
 * ): array<array-key, mixed>
 * @phpstan-type Finalizer callable(
 *     array<array-key, mixed>, array<array-key, mixed>, ?array<string, mixed>, string, ?string
 * ): array<array-key, mixed>
 * @phpstan-type Projection callable(
 *     array{action: string, query?: array<array-key, mixed>}
 * ): ?array<string, mixed>
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
     * Referenced controller schemas resolved once within this graph's lifetime.
     *
     * @var array<string, Record>
     */
    private array $controllers = [];

    /**
     * Application boundary that resolves a controller declaration to its pure record schema.
     *
     * @var Closure(string): Record
     */
    private readonly Closure $controllerUsing;

    /**
     * Graph edges normalized with their native names and collection cardinalities.
     *
     * @var array<string, RelationOptions&array{name: string, many: bool}>
     */
    private readonly array $relations;

    /**
     * Keep application differences independent of graph traversal and schema lifetime.
     *
     * @param array<string, callable(mixed): mixed>                              $values
     * @param array<string, Relation>                                            $relations
     * @param list<AttributeMapper>                                              $attributes
     * @param list<Finalizer>                                                    $after
     * @param (callable(string): array<string, string>)|null                     $fieldsUsing
     * @param callable(string): Record                                           $controllerUsing
     * @param array{attributes?: list<AttributeMapper>, after?: list<Finalizer>} $rootCallbacks
     */
    public function __construct(
        /**
         * Immutable record settings shared by resource schemas in this graph.
         */
        private readonly Record $prototype,
        /**
         * Field value formatters applied before record normalization.
         */
        private readonly array $values,
        array $relations,
        /**
         * Shared native normalizers applied to root and identifier-based child records.
         */
        private readonly array $attributes,
        /**
         * Shared derived-field callbacks applied after relation traversal.
         */
        private readonly array $after,
        callable $controllerUsing,
        ?callable $fieldsUsing = null,
        /**
         * Resource callbacks isolated from identifier-based child schemas.
         */
        private readonly array $rootCallbacks = [],
    ) {
        $normalized = [];
        foreach ($relations as $name => $relation) {
            $relation = (is_string($relation) ? ['controller' => $relation] : $relation)
                + ['name' => $name, 'many' => true];
            if (isset($relation['identifier']) === isset($relation['controller'])) {
                throw new \InvalidArgumentException(
                    'A relation requires exactly one identifier or controller.',
                );
            }
            $normalized[$name] = $relation;
        }
        $this->relations = $normalized;
        $this->controllerUsing = $controllerUsing(...);
        $this->fieldsUsing = $fieldsUsing === null ? null : $fieldsUsing(...);
    }

    /**
     * Reuse immutable schemas; requested projections remain local to each traversal.
     *
     * @param RelationOptions $options
     */
    public function record(
        string $identifier, ?string $relation = null, array $options = [],
    ): Record {
        $key = $identifier."\0".$relation;
        if (isset($this->records[$key])) {
            return $this->records[$key];
        }

        $record = $this->schema($identifier, $relation, $options);
        foreach ($this->relations as $source => $child) {
            $schema = fn (): Record => $this->relatedRecord($source, $child);
            $record = $child['many']
                ? $record->many($source, $schema, $child['name'])
                : $record->one($source, $schema, $child['name']);
        }

        return $this->records[$key] = $record;
    }

    /**
     * Compile one resource's attribute mapping and callbacks independently of its edges.
     *
     * @param RelationOptions $options
     */
    private function schema(string $identifier, ?string $relation, array $options): Record
    {
        $fields = $this->attributeNames($identifier, $relation, $options['fields'] ?? []);
        $nativeField = strtolower($identifier);
        $source = ($fields[$nativeField] ?? null) === $identifier ? $nativeField : 'id';
        $record = $this->prototype
            ->identifier($identifier, $source)
            ->rename($fields);
        if ($relation !== null) {
            $record = $record->required([]);
        }
        $record = $record->attributesUsing(
            fn (array $attributes): array => $this->normalize(
                $attributes, $identifier, $relation, $options,
            ),
        );
        $record->schema()->inverse();
        $after = $relation === null
            ? [...$this->after, ...($this->rootCallbacks['after'] ?? [])]
            : $this->after;
        foreach ($after as $finalize) {
            $record = $record->afterUsing(
                static fn (array $mapped, array $raw, ?array $projection): array => $finalize(
                    $mapped, $raw, $projection, $identifier, $relation,
                ),
            );
        }

        return $record;
    }

    /**
     * Resolve root and child naming precedence while retaining each child's identifier.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function attributeNames(
        string $identifier, ?string $relation, array $overrides,
    ): array {
        $differences = $this->fieldsUsing === null ? [] : ($this->fieldsUsing)($identifier);
        $declared = $this->prototype->schema()->names();
        $fields = $relation === null
            ? array_replace($differences, $declared)
            : array_replace($declared, $differences);
        $fields = array_replace($fields, $overrides);
        if ($relation !== null) {
            unset($fields['id']);
        }

        return $fields;
    }

    /**
     * Apply field formatters, edge normalization and resource-specific callbacks in order.
     *
     * @param array<array-key, mixed> $attributes
     * @param RelationOptions         $options
     *
     * @return array<array-key, mixed>
     */
    private function normalize(
        array $attributes, string $identifier, ?string $relation, array $options,
    ): array {
        $values = $attributes;
        foreach ($this->values as $field => $format) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $format($values[$field]);
            }
        }
        if (isset($options['attributes'])) {
            $values = ($options['attributes'])($values);
        }
        $normalizers = $relation === null
            ? [...($this->rootCallbacks['attributes'] ?? []), ...$this->attributes]
            : $this->attributes;
        foreach ($normalizers as $map) {
            $values = $map($values, $identifier, $relation);
        }

        return $values;
    }

    /**
     * Resolve one lazy graph edge and layer its role-specific output differences.
     *
     * @param RelationOptions $child
     */
    private function relatedRecord(string $source, array $child): Record
    {
        $schema = isset($child['controller'])
            ? $this->controllerRecord($child['controller'])
            : $this->record(
                $child['identifier']
                    ?? throw new \InvalidArgumentException('Missing relation identifier.'),
                $source,
                $child,
            );
        if (isset($child['fields'])) {
            $schema = $schema->rename($child['fields']);
        }
        if (isset($child['except'])) {
            $schema = $schema->except($child['except']);
        }

        return isset($child['after']) ? $schema->afterUsing($child['after']) : $schema;
    }

    /**
     * Cache a referenced controller's schema without sharing it across request graphs.
     */
    private function controllerRecord(string $controller): Record
    {
        return $this->controllers[$controller] ??= ($this->controllerUsing)($controller);
    }
}
