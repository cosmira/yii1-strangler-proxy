<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Response\Json;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * Project a plain JSON object onto the same declared fields on writes and reads.
 *
 * @internal
 */
final readonly class JsonFields
{
    /**
     * Required JSON properties in declaration order.
     *
     * @var array<string, string>
     */
    private array $fields;

    /**
     * Resolve incoming key variants to the public name before checking for conflicts.
     *
     * @var array<string, string>
     */
    private array $names;

    /**
     * Nullable types permit a null value while still requiring the key.
     *
     * @param array<array-key, string> $fields
     */
    public function __construct(array $fields)
    {
        if ($fields === []) {
            throw new InvalidArgumentException('Declare at least one JSON field.');
        }

        $names = [];
        $types = [];
        foreach ($fields as $name => $type) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('JSON field names must be nonempty strings.');
            }
            $key = strtolower($name);
            if (isset($names[$key])) {
                throw new InvalidArgumentException('Duplicate JSON field: '.$name.'.');
            }
            $base = str_ends_with($type, '|null') ? substr($type, 0, -5) : $type;
            if (! in_array($base, ['string', 'bool', 'int', 'list<string>'], true)) {
                throw new InvalidArgumentException('Unsupported JSON field type for '.$name.'.');
            }
            $names[$key] = $name;
            $types[$name] = $type;
        }
        $this->names = $names;
        $this->fields = $types;
    }

    /**
     * Select submitted values case-insensitively and normalize native integer toggles.
     *
     * @return array<string, mixed>|false
     */
    public function payload(mixed $source): array|false
    {
        if (! is_array($source) && ! $source instanceof stdClass) {
            return false;
        }
        $record = [];
        foreach ((array) $source as $key => $value) {
            $name = $this->names[strtolower((string) $key)] ?? null;
            if ($name === null) {
                continue;
            }
            if (str_starts_with($this->fields[$name], 'bool') && in_array($value, [0, 1], true)) {
                $value = (bool) $value;
            }
            if (array_key_exists($name, $record) && $record[$name] !== $value) {
                return false;
            }
            $record[$name] = $value;
        }

        return $this->project($record);
    }

    /**
     * Retain null success, validate upstream objects strictly and omit private properties.
     */
    public function response(mixed $json, ResponseInterface $response): ResponseInterface
    {
        if (in_array($response->getStatusCode(), [204, 205], true)) {
            return Json::response($response, null);
        }
        if (! $json instanceof stdClass || ! property_exists($json, 'data')) {
            throw new InvalidUpstreamResponse('Expected a JSON object with a data property.');
        }
        $success = ['state' => 'data'];
        if ($json->data === null) {
            return Json::response($response, $success);
        }
        $data = $json->data instanceof stdClass ? $this->project((array) $json->data) : false;
        if ($data === false) {
            throw new InvalidUpstreamResponse(
                'Upstream data does not match the declared JSON fields.',
            );
        }

        return Json::response($response, $success + ['data' => $data]);
    }

    /**
     * An absent property, malformed list or wrong scalar type invalidates the entire record.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>|false
     */
    private function project(array $values): array|false
    {
        $selected = [];
        foreach ($this->fields as $name => $type) {
            if (! array_key_exists($name, $values)) {
                return false;
            }
            $value = $values[$name];
            if (! self::accepts($type, $value)) {
                return false;
            }
            $selected[$name] = $value;
        }

        return $selected;
    }

    /**
     * JSON numbers, booleans and strings keep their original types in backend responses.
     */
    private static function accepts(string $type, mixed $value): bool
    {
        if ($value === null) {
            return str_ends_with($type, '|null');
        }

        $base = explode('|', $type, 2)[0];

        if ($base === 'list<string>') {
            return is_array($value) && array_is_list($value)
                && array_filter($value, is_string(...)) === $value;
        }

        return get_debug_type($value) === $base;
    }
}
