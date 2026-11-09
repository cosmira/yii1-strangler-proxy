<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Internal\Payload;

use InvalidArgumentException;

/** Keep unsupported native requests out of the upstream transport. */
final readonly class NativeRequestGuard
{
    /**
     * Declare native request limitations before any HTTP attempt.
     *
     * @param list<string>          $nativeOn
     * @param array<string, string> $required
     * @param list<string>          $methods
     * @param list<string>          $actions
     */
    public function __construct(
        /**
         * Payload keys whose native behavior has no upstream equivalent.
         */
        private array $nativeOn,
        /**
         * Required payload fields and their accepted native value shapes.
         */
        private array $required,
        /**
         * Native HTTP methods accepted by these actions.
         */
        private array $methods,
        /**
         * Actions governed by this declaration; an empty list applies to all actions.
         */
        private array $actions,
    ) {
        foreach ([...$nativeOn, ...array_keys($required), ...$methods, ...$actions] as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException('Request guard names must be nonempty strings.');
            }
        }
        foreach ($required as $type) {
            if (! in_array($type, ['identifier', 'rows'], true)) {
                throw new InvalidArgumentException('Unsupported required request field type.');
            }
        }
        foreach ($methods as $method) {
            if (! preg_match('/^[A-Z]+$/', $method)) {
                throw new InvalidArgumentException(
                    'Request guard methods must be uppercase HTTP methods.',
                );
            }
        }
    }

    /**
     * Compose action-specific exceptions to native relation handling.
     *
     * @param array<string, list<string>> $registered
     * @param list<string>                $relations
     * @param list<string>                $actions
     *
     * @return array<string, list<string>>
     */
    public static function adaptRelations(
        array $registered, array $relations, array $actions,
    ): array {
        foreach ($relations as $relation) {
            if (trim($relation) === '') {
                throw new InvalidArgumentException('Adapted relation names must be nonempty.');
            }
        }
        if ($relations === []) {
            return $registered;
        }
        $names = array_map(strtolower(...), $relations);
        foreach ($actions ?: ['*'] as $action) {
            $registered[$action] = array_merge($registered[$action] ?? [], $names);
        }

        return $registered;
    }

    /**
     * Select native execution when an applicable request violates a declared limitation.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $query
     */
    public function bypass(
        array $payload, string $action, array $query, string $method, ?string $identifier,
    ): bool {
        if ($this->actions !== [] && ! in_array($action, $this->actions, true)) {
            return false;
        }

        return ($this->methods !== [] && ! in_array($method, $this->methods, true))
            || $this->hasUnsupportedFields($payload)
            || $this->hasConflictingIdentity($payload, $query, $identifier)
            || $this->hasInvalidRequiredFields($payload, $query, $identifier);
    }

    /**
     * Compare unsupported field names without depending on the request's letter case.
     *
     * @param array<array-key, mixed> $payload
     */
    private function hasUnsupportedFields(array $payload): bool
    {
        return array_intersect(
            array_map(strtoupper(...), array_keys($payload)),
            array_map(strtoupper(...), $this->nativeOn),
        ) !== [];
    }

    /**
     * Keep URL and payload identifiers consistent without changing either snapshot.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $query
     */
    private function hasConflictingIdentity(
        array $payload, array $query, ?string $identifier,
    ): bool {
        if ($identifier === null || ! isset($query['id'], $payload[$identifier])) {
            return false;
        }

        return ! is_scalar($query['id']) || ! is_scalar($payload[$identifier])
            || (string) $query['id'] !== (string) $payload[$identifier];
    }

    /**
     * Resolve required values from the payload, using the URL only for its own identity.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $query
     */
    private function hasInvalidRequiredFields(
        array $payload, array $query, ?string $identifier,
    ): bool {
        foreach ($this->required as $field => $type) {
            $value = $payload[$field] ?? ($field === $identifier ? ($query['id'] ?? null) : null);
            if (! $this->acceptsRequiredValue($value, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accept nonempty scalar identities or array rows, retaining keyed row collections.
     */
    private function acceptsRequiredValue(mixed $value, string $type): bool
    {
        if ($type === 'identifier') {
            return is_scalar($value) && trim((string) $value) !== '';
        }

        return is_array($value)
            && array_filter($value, static fn (mixed $row): bool => ! is_array($row)) === [];
    }
}
