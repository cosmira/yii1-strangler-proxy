<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Mapping;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Compile the ordinary naming and envelope rules behind the public builder.
 *
 * @phpstan-type RequestRule array{lists: array<string, string>, defaults: array<string, mixed>}
 * @phpstan-type RequestRules array<string, RequestRule>
 *
 * @internal
 */
final class Convention
{
    /**
     * Application response outcomes that precede ordinary record mapping.
     *
     * @var (\Closure(mixed, ResponseInterface, array{action: string}): ?ResponseInterface)|null
     */
    private ?\Closure $resultUsing;

    /**
     * A shape adapter receives unchanged defaults rather than calling itself recursively.
     *
     * @var (\Closure(
     *     array<array-key, mixed>, array{action: string}, self
     * ): (array<array-key, mixed>|stdClass|false))|null
     */
    private ?\Closure $adapter = null;

    /**
     * Immutable configuration supplied to the shape adapter.
     */
    private self $defaults;

    /**
     * Input names are independent of response aliases.
     */
    private Fields $input;
    /**
     * The default Yii success envelope and its response-only record schema.
     */
    private JsonResource $output;

    /**
     * Bind compiled response settings to the input naming and value differences.
     *
     * @param array<string, string>                 $requestFields
     * @param array<string, callable(mixed): mixed> $requestValues
     * @param (callable(
     *     mixed, ResponseInterface, array{action: string}
     * ): ?ResponseInterface)|null $resultUsing
     * @param array<string, string> $fields
     * @param RequestRules          $rules
     */
    public function __construct(
        /**
         * Native identifier omitted from writable attributes.
         */
        private readonly string $identifier,
        JsonResource $output,
        private array $requestFields = [],
        private array $requestValues = [],
        ?callable $resultUsing = null,
        private array $rules = [],
        array $fields = [],
    ) {
        $this->input = Fields::uppercase()->rename($fields);
        $this->output = $output;
        $this->resultUsing = $resultUsing === null ? null : $resultUsing(...);
    }

    /**
     * Accumulate shared or action-specific request differences without replacing other rules.
     *
     * @param RequestRules          $registered
     * @param array<string, string> $lists
     * @param array<string, mixed>  $defaults
     * @param list<string>          $actions
     *
     * @return RequestRules
     */
    public static function requestRules(
        array $registered, array $lists, array $defaults, array $actions,
    ): array {
        if ($lists === [] && $defaults === []) {
            return $registered;
        }
        Mapping::fields([], $lists);
        self::validateDefaultNames($defaults);
        foreach ($actions ?: ['*'] as $action) {
            if ($action === '') {
                throw new InvalidArgumentException('Mapping actions must not be empty.');
            }
            $action = strtolower($action);
            $rules = $registered[$action] ?? ['lists' => [], 'defaults' => []];
            $rules['lists'] = array_replace($rules['lists'], $lists);
            Mapping::fields([], $rules['lists']);
            $rules['defaults'] = array_replace($rules['defaults'], $defaults);
            $registered[$action] = $rules;
        }

        return $registered;
    }

    /**
     * Reject malformed upstream default names at the configuration boundary.
     *
     * @param array<array-key, mixed> $defaults
     */
    private static function validateDefaultNames(array $defaults): void
    {
        foreach (array_keys($defaults) as $field) {
            if (! is_string($field) || $field === '') {
                throw new InvalidArgumentException(
                    'Request default names must be nonempty strings.',
                );
            }
        }
    }

    /**
     * Restore the input alias actually supplied when several aliases target one field.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, string>
     */
    public function validationFields(array $payload): array
    {
        return array_flip(array_intersect_key($this->requestFields, $payload));
    }

    /**
     * Apply query-only names without changing writable attributes.
     *
     * @param array<string, string> $filterAliases
     * @param array<string, string> $sortAliases
     */
    public function queryMapper(array $filterAliases = [], array $sortAliases = []): Query
    {
        $input = $this->input;
        foreach ($filterAliases as $field => $name) {
            $input = $input->filterAs($field, $name);
        }
        foreach ($sortAliases as $field => $name) {
            $input = $input->sortAs($field, $name);
        }

        return Query::legacy($input)->pagination(max: PHP_INT_MAX);
    }

    /**
     * Apply a shape difference while retaining the ordinary naming and value rules.
     *
     * @param callable(
     *     array<array-key, mixed>, array{action: string}, self
     * ): (array<array-key, mixed>|stdClass|false) $adapter
     */
    public function adapt(callable $adapter): self
    {
        $copy = clone $this;
        $copy->adapter = $adapter(...);
        $copy->defaults = $this;

        return $copy;
    }

    /**
     * Translate model attributes while leaving opaque nested JSON values intact.
     *
     * @param array<array-key, mixed>|stdClass $payload
     * @param array{action: string}            $context
     *
     * @return array<array-key, mixed>|stdClass|false
     */
    public function payload(
        array|stdClass $payload, array $context = ['action' => ''],
    ): array|stdClass|false {
        if ($this->adapter !== null) {
            return ($this->adapter)((array) $payload, $context, $this->defaults);
        }
        $values = (array) $payload;
        unset($values[$this->identifier]);

        try {
            $shared = $this->rules['*'] ?? ['lists' => [], 'defaults' => []];
            $scoped = $this->rules[strtolower($context['action'])]
                ?? ['lists' => [], 'defaults' => []];
            $lists = array_replace($shared['lists'], $scoped['lists']);
            $defaults = array_replace($shared['defaults'], $scoped['defaults']);
            $lists = array_filter(
                $lists, static fn (string $source): bool => isset($values[$source]),
                ARRAY_FILTER_USE_KEY,
            );
            foreach ($lists as $source => $target) {
                $values[$source] = [$values[$source]];
            }
            $names = array_replace(
                $this->input->writes(array_keys($values)),
                array_intersect_key($this->requestFields, $values),
                array_intersect_key($lists, $values),
            );
            $record = $payload instanceof stdClass ? (object) $values : $values;
            $mapped = (array) Mapping::fields($record, $names) + $defaults;
            $record = $payload instanceof stdClass ? (object) $mapped : $mapped;

            return self::values($record, $this->requestValues);
        } catch (InvalidArgumentException) {
            return false;
        }

    }

    /**
     * Format ordinary successful items, collections and empty responses.
     *
     * @param array{action: string} $context
     */
    public function response(
        mixed $json, ResponseInterface $response, array $context,
    ): ResponseInterface {
        $result = $this->resultUsing === null
            ? null : ($this->resultUsing)($json, $response, $context);

        return $result ?? ($this->output)($json, $response, $context);
    }

    /**
     * Change declared attribute values only, retaining nulls, JSON kind and opaque nested keys.
     *
     * @param array<array-key, mixed>|stdClass      $record
     * @param array<string, callable(mixed): mixed> $formatters
     *
     * @return ($record is stdClass ? stdClass : array<array-key, mixed>)
     */
    private static function values(array|stdClass $record, array $formatters): array|stdClass
    {
        $values = (array) $record;
        foreach ($formatters as $field => $format) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $format($values[$field]);
            }
        }

        return $record instanceof stdClass ? (object) $values : $values;
    }
}
