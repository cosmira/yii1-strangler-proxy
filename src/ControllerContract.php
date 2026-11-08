<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CActiveRecord;
use CController;
use Cosmira\Strangler\Response\ErrorResponse;
use Cosmira\Strangler\Response\Json;
use Cosmira\Strangler\Response\Record;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Connect application controller conventions without coupling transport to the application.
 *
 * @phpstan-import-type Relation from \Cosmira\Strangler\Response\ResponseGraph
 */
abstract class ControllerContract
{
    /**
     * Read a related controller's record declaration without executing its lifecycle.
     *
     * @internal
     */
    public static function responseRecord(string $class): Record
    {
        if (! is_a($class, CController::class, true)) {
            throw new InvalidArgumentException('Related controller must extend CController.');
        }
        $application = \Yii::app();
        $current = $application instanceof \CWebApplication ? $application->getController() : null;
        $controller = new $class('strangler', $current?->getModule());
        $records = self::declaredRecords($controller);
        if (count($records) !== 1) {
            throw new InvalidArgumentException(
                'Related controller '.$class.' must declare exactly one Strangler response record.',
            );
        }

        return $records[0];
    }

    /**
     * Find compiled record declarations while ignoring native controller filters.
     *
     * @return list<Record>
     */
    private static function declaredRecords(CController $controller): array
    {
        $records = [];
        foreach ($controller->filters() as $filter) {
            if (! is_array($filter) || ($filter[0] ?? null) !== StranglerFilter::class) {
                continue;
            }
            $config = $filter['config'] ?? null;
            if (is_array($config) && ($config['responseRecord'] ?? null) instanceof Record) {
                $records[] = $config['responseRecord'];
            }
        }

        return $records;
    }

    /**
     * Resolve the controller's native model with its existing scope.
     */
    abstract public function model(CController $controller): CActiveRecord;

    /**
     * Read the application's request envelope once before adapting it.
     *
     * @return array<array-key, mixed>
     */
    abstract public function payload(CController $controller): array;

    /**
     * Supply a native identifier once when the application contract already owns it.
     */
    public function identifier(): ?string
    {
        return null;
    }

    /**
     * Declare shared relationship differences without enumerating ordinary attributes.
     *
     * @return array<string, Relation>
     */
    public function relations(): array
    {
        return [];
    }

    /**
     * Supply naming differences shared by root and related representations of one resource.
     *
     * @return array<string, string>
     */
    public function responseFields(string $identifier): array
    {
        return [];
    }

    /**
     * Normalize application scalar values before the shared graph maps their names.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @return array<array-key, mixed>
     */
    public function responseAttributes(
        array $attributes, string $identifier, ?string $relation,
    ): array {
        return $attributes;
    }

    /**
     * Add application-derived values after the shared graph maps related records.
     *
     * @param array<array-key, mixed>   $mapped
     * @param array<array-key, mixed>   $raw
     * @param array<string, mixed>|null $projection
     *
     * @return array<array-key, mixed>
     */
    public function responseAfter(
        array $mapped, array $raw, ?array $projection, string $identifier, ?string $relation,
    ): array {
        return $mapped;
    }

    /**
     * Resolve application selection defaults once for each response graph.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    public function responseProjection(array $context): ?array
    {
        return null;
    }

    /**
     * Validate the final mapped response before any output is emitted.
     */
    public function validateResponse(ResponseInterface $response): void {}

    /**
     * Replace application-specific operation results before the record graph is traversed.
     *
     * @param array<array-key, mixed> $context
     */
    public function responseResult(
        mixed $json, ResponseInterface $response, array $context,
    ): ?ResponseInterface {
        return null;
    }

    /**
     * Supply native validation codes when the application has a different error vocabulary.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>|null
     */
    public function validationErrors(
        CController $controller, string $action, array $payload,
    ): ?array {
        return null;
    }

    /**
     * Map ordinary HTTP failures into the application's native error codes.
     *
     * @return array<int, string>
     */
    public function errorCodes(): array
    {
        return [
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            500 => 'internal_error',
            503 => 'service_temporarily_unavailable',
        ];
    }

    /**
     * Preserve native validation codes without re-reading or saving the request payload.
     *
     * @param array{
     *     action?: string, query?: array<array-key, mixed>,
     *     payload?: array<array-key, mixed>|null, identifier?: string,
     *     responseFields?: array<string, string>
     * } $context
     */
    public function error(
        mixed $json, ResponseInterface $response, array $context,
    ): ResponseInterface {
        $fields = $context['responseFields'] ?? [];
        if ($response->getStatusCode() === 422 && ! isset($context['responseFields'])) {
            $controller = StranglerProxy::application()->getController();
            $identifier = $context['identifier'] ?? $this->identifier()
                ?? $this->model($controller)->primaryKey();
            $fields = $this->responseFields(is_string($identifier) ? $identifier : '');
        }
        $mapped = (new ErrorResponse($fields, $this->errorCodes()))($json, $response);
        $action = $context['action'] ?? '';
        if ($response->getStatusCode() === 422
            && in_array($action, ['create', 'edit'], true)) {
            $errors = $this->validationErrors(
                StranglerProxy::application()->getController(), $action, $context['payload'] ?? [],
            );
            if ($errors !== null) {
                return Json::response($mapped, $errors);
            }
        }

        return $mapped;
    }

    /**
     * Keep malformed CRUD requests on the native action using the existing payload snapshot.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     */
    public function shouldBypass(
        CController $controller, string $action, array $query, array $payload,
    ): bool {
        $method = $this->conventionalMethod($action);
        if ($method === null) {
            return false;
        }

        return StranglerProxy::application()->getRequest()->getRequestType() !== $method
            || $this->malformedQuery($action, $query)
            || ($payload !== [] && array_is_list($payload))
            || $this->conflictingIdentifier($controller, $query, $payload);
    }

    /**
     * Leave application-specific actions outside conventional CRUD method checks.
     */
    private function conventionalMethod(string $action): ?string
    {
        return match ($action) {
            'get', 'view' => 'GET',
            'create'      => 'POST',
            'edit'        => 'PUT',
            'delete'      => 'DELETE',
            default       => null,
        };
    }

    /**
     * Reject malformed filter containers and item identifiers on collection requests.
     *
     * @param array<array-key, mixed> $query
     */
    private function malformedQuery(string $action, array $query): bool
    {
        return ! is_array($query['filter'] ?? []) || ($action === 'get' && isset($query['id']));
    }

    /**
     * Preserve one native record identity across the URL and request envelope.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     */
    private function conflictingIdentifier(
        CController $controller, array $query, array $payload,
    ): bool {
        if (! isset($query['id']) || $payload === []) {
            return false;
        }
        $identifier = $this->identifier() ?? $this->model($controller)->primaryKey();

        return is_string($identifier) && isset($payload[$identifier])
            && $query['id'] !== $payload[$identifier];
    }

    /**
     * Resolve a parent from a scoped native record without mutating the controller's finder.
     *
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, int|string>|false
     */
    final public function parentParameters(
        CController $controller, string $parent, array $query, array $payload, ?string $identifier,
    ): array|false {
        $finder = clone $this->model($controller);
        $finder->setDbCriteria(clone $finder->getDbCriteria());
        $key = $identifier ?? $finder->primaryKey();
        if (! is_string($key)) {
            throw new InvalidArgumentException(
                'Scoped parent lookup requires one native identifier.',
            );
        }
        $id = $query['id'] ?? $payload[$key] ?? null;
        $value = $id === null
            ? ($payload[$parent] ?? null)
            : $this->scopedParent($finder, $id, $parent, $payload);
        if ((! is_string($value) && ! is_int($value)) || $value === '') {
            return false;
        }

        return [strtolower($parent) => $value];
    }

    /**
     * Resolve existing ownership without widening the native model's current scope.
     *
     * @param array<array-key, mixed> $payload
     */
    private function scopedParent(
        CActiveRecord $finder, mixed $id, string $parent, array $payload,
    ): mixed {
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }
        $model = $finder->findByPk($id);
        if ($model === null) {
            return null;
        }
        $value = $model->$parent;

        return isset($payload[$parent]) && $payload[$parent] !== $value ? null : $value;
    }
}
