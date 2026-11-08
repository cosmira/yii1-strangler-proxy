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
     * @param array<string, string>                 $filterAliases
     * @param array<string, string>                 $sortAliases
     * @param (callable(
     *     mixed, ResponseInterface, array{action: string}
     * ): ?ResponseInterface)|null $resultUsing
     */
    public function __construct(
        /**
         * Native identifier omitted from writable attributes.
         */
        private readonly string $identifier,
        JsonResource $output,
        private array $requestFields = [],
        private array $requestValues = [],
        array $filterAliases = [],
        array $sortAliases = [],
        ?callable $resultUsing = null,
    ) {
        $input = Fields::uppercase()->rename(array_flip($requestFields));
        foreach ($filterAliases as $field => $name) {
            $input = $input->filterAs($field, $name);
        }
        foreach ($sortAliases as $field => $name) {
            $input = $input->sortAs($field, $name);
        }
        $this->input = $input;
        $this->output = $output;
        $this->resultUsing = $resultUsing === null ? null : $resultUsing(...);
    }

    /**
     * Share the compiled query convention with application semantic adapters.
     */
    public function queryMapper(): Query
    {
        return Query::legacy($this->input)->pagination(max: PHP_INT_MAX);
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
            $names = array_replace(
                $this->input->writes(array_keys($values)),
                array_intersect_key($this->requestFields, $values),
            );
            $mapped = Mapping::fields($payload, $names);

            return self::values($mapped, $this->requestValues);
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
