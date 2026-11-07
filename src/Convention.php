<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Cosmira\Strangler\Schema\Fields;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Compile the ordinary naming and envelope rules behind the public builder.
 *
 * @internal
 */
final readonly class Convention
{
    /**
     * Input names are independent of response aliases.
     */
    private Fields $input;
    /**
     * The default Yii success envelope and its response-only record schema.
     */
    private JsonResource $output;

    /**
     * Compile the directional exceptions once, when the filter is built.
     *
     * @param string                                     $identifier     Read-only Yii key.
     * @param array<string, string>                      $responseFields
     * @param array<string, string>                      $requestFields
     * @param array<string, callable(mixed): mixed>      $requestValues
     * @param array<string, callable(mixed): mixed>      $responseValues
     * @param array<string, string>                      $filterAliases
     * @param array<string, string>                      $sortAliases
     * @param list<callable(JsonResource): JsonResource> $configure
     */
    public function __construct(
        private string $identifier,
        array $responseFields = [],
        array $requestFields = [],
        private array $requestValues = [],
        array $responseValues = [],
        array $filterAliases = [],
        array $sortAliases = [],
        array $configure = [],
    ) {
        Mapping::fields([], $requestFields);
        $input = Fields::uppercase()
            ->rename(array_flip($requestFields));
        foreach ($filterAliases as $field => $name) {
            $input = $input->filterAs($field, $name);
        }
        foreach ($sortAliases as $field => $name) {
            $input = $input->sortAs($field, $name);
        }
        $this->input = $input;
        $record = Record::uppercase()->identifier($identifier)->rename($responseFields)
            ->attributesUsing(
                static fn (array $attributes): array => self::values($attributes, $responseValues),
            );
        $record->schema()->inverse();
        $resource = JsonResource::make()->recordUsing($record);
        foreach ($configure as $extend) {
            $resource = $extend($resource);
        }
        $this->output = $resource;
    }

    /**
     * Share the compiled query convention with application semantic adapters.
     */
    public function queryMapper(): Query
    {
        return Query::legacy($this->input)->pagination(max: PHP_INT_MAX);
    }

    /**
     * Translate model attributes while leaving opaque nested JSON values intact.
     *
     * @param array<array-key, mixed>|stdClass $payload
     *
     * @return array<array-key, mixed>|stdClass|false
     */
    public function payload(array|stdClass $payload): array|stdClass|false
    {
        $values = (array) $payload;
        unset($values[$this->identifier]);

        try {
            $mapped = Mapping::fields($payload, $this->input->writes(array_keys($values)));
        } catch (InvalidArgumentException) {
            return false;
        }

        return self::values($mapped, $this->requestValues);
    }

    /**
     * Format ordinary successful items, collections and empty responses.
     *
     * @param array{action: string} $context
     */
    public function response(
        mixed $json, ResponseInterface $response, array $context,
    ): ResponseInterface {
        return ($this->output)($json, $response, $context);
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
