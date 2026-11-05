<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

/** Callable legacy query configuration for Strangler::mapQuery(). */
final class Query
{
    /**
     * Legacy filter names mapped to upstream filter names.
     *
     * @var array<array-key, mixed>
     */
    private array $filterFields = [];
    /**
     * Legacy sort fields accepted by the upstream query.
     *
     * @var array<array-key, mixed>
     */
    private array $sortFields = [];
    /**
     * Legacy relation names mapped to upstream includes.
     *
     * @var array<array-key, mixed>
     */
    private array $includeFields = [];
    /**
     * Actions that retain offset pagination during query translation.
     *
     * @var list<string>
     */
    private array $listActions = ['get'];
    /**
     * Transport-only query keys removed before supported-query checks.
     *
     * @var list<string>
     */
    private array $ignored = ['id', '_'];
    /**
     * Page size used when the client omits its limit.
     */
    private int $defaultLimit = 20;
    /**
     * Largest page size accepted by the upstream contract.
     */
    private int $maxLimit = 100;

    /**
     * Keep construction behind the legacy query factory.
     */
    private function __construct() {}

    /**
     * Start with legacy list pagination and transport-key defaults.
     */
    public static function legacy(): self
    {
        return new self();
    }

    /**
     * Select the legacy filters that can reach the upstream API.
     *
     * @param array<array-key, mixed> $fields
     */
    public function filters(array $fields): self
    {
        $copy = clone $this;
        $copy->filterFields = $fields;

        return $copy;
    }

    /**
     * Translate supported legacy ordering fields to upstream names.
     *
     * @param array<array-key, mixed> $fields
     */
    public function sorts(array $fields): self
    {
        $copy = clone $this;
        $copy->sortFields = $fields;

        return $copy;
    }

    /**
     * Translate supported legacy relations to upstream includes.
     *
     * @param array<array-key, mixed> $fields
     */
    public function includes(array $fields): self
    {
        $copy = clone $this;
        $copy->includeFields = $fields;

        return $copy;
    }

    /**
     * Set the default page size and the upstream page-size ceiling.
     */
    public function pagination(int $default = 20, int $max = 100): self
    {
        $copy = clone $this;
        $copy->defaultLimit = $default;
        $copy->maxLimit = $max;

        return $copy;
    }

    /**
     * Replace the actions that forward translated pagination.
     *
     * @param list<string> $actions
     */
    public function lists(array $actions): self
    {
        $copy = clone $this;
        $copy->listActions = $actions;

        return $copy;
    }

    /**
     * Replace the transport-only keys removed before query validation.
     *
     * @param list<string> $fields
     */
    public function ignore(array $fields): self
    {
        $copy = clone $this;
        $copy->ignored = $fields;

        return $copy;
    }

    /**
     * Translate supported queries and retain legacy execution for unsupported values.
     *
     * @param array<array-key, mixed> $query
     * @param array{action: string}   $context
     *
     * @return array<array-key, mixed>|false
     */
    public function __invoke(array $query, array $context): array|false
    {
        $values = array_diff_key($query, array_flip($this->ignored));
        $list = in_array($context['action'], $this->listActions, true);
        if (! $list) {
            unset($values['start'], $values['limit']);
        }
        $mapped = Mapping::legacyQuery($values, $this->filterFields, $this->sortFields,
            $this->includeFields, $this->defaultLimit, $this->maxLimit);
        if ($mapped !== false && ! $list) {
            unset($mapped['page'], $mapped['per_page']);
        }

        return $mapped;
    }
}
