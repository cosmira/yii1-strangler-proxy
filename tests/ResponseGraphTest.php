<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\ControllerContract;
use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Query;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Response\Record;
use Cosmira\Strangler\Strangler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ResponseGraphTest extends TestCase
{
    public function testContractIdentifiersApplyToRootAndNestedRecordsAndMetadata(): void
    {
        $contract = new class extends ControllerContract
        {
            public function model(\CController $controller): \CActiveRecord
            {
                throw new \LogicException('Rendering must not query native models.');
            }

            public function payload(\CController $controller): array
            {
                return [];
            }

            public function responseFields(string $identifier): array
            {
                return [strtolower($identifier) => $identifier];
            }
        };
        $builder = Strangler::proxy('catalog')->using($contract)->uppercase('ITEM_ID')
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID']])
            ->get('view', '/items/{id}');

        self::assertSame(['ITEM_ID' => 'root', 'children' => [['CHILD_ID' => 'child']]],
            $this->map($builder, ['data' => ['item_id' => 'root', 'children' => [['child_id' => 'child']]]])['data']);
        $config = ControllerContract::resolveConfig($builder->build()['config'], new \CController('catalog'));
        self::assertSame(['item_id' => 'ITEM_ID'], $config['mappings']['responseFields'] ?? []);
        $schema = $config['responseRecord'] ?? null;
        self::assertInstanceOf(Record::class, $schema);
        self::assertSame(['ITEM_ID' => 'root'], $schema(['item_id' => 'root']));

        foreach ([['id' => 'obsolete'], ['item_id' => 'root', 'children' => [['id' => 'obsolete']]]] as $record) {
            try {
                $schema($record);
                self::fail('Missing database identifiers must be rejected.');
            } catch (InvalidUpstreamResponse $exception) {
                self::assertSame('Missing record identifier.', $exception->getMessage());
            }
        }
    }

    public function testOneFluentObjectTranslatesRecursiveRelationsAndKeepsOpaqueJsonUntouched(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(fields: ['updated_at' => 'CHANGE_DATE'], except: ['internal'], relations: [
                'children' => ['identifier' => 'CHILD_ID', 'name' => 'descendants', 'many' => true],
                'owner'    => ['identifier' => 'OWNER_ID', 'name' => 'owner', 'many' => false],
            ])
            ->get('view', '/items/{id}');
        $json = json_decode('{"data":{"id":"root","updated_at":"today","internal":1,"meta":{"originalKey":true},"children":[{"id":"child","internal":2,"children":[],"owner":{"id":"owner","owner":null}},{"id":"second","children":[],"owner":{"id":"second-owner","owner":null}}]}}');

        $mapped = $this->map($builder, $json);

        self::assertSame(['state' => 'data', 'data' => [
            'ITEM_ID'      => 'root', 'CHANGE_DATE' => 'today', 'META' => ['originalKey' => true],
            'descendants'  => [[
                'CHILD_ID' => 'child', 'descendants' => [],
                'owner'    => ['OWNER_ID' => 'owner', 'owner' => null],
            ], ['CHILD_ID' => 'second', 'descendants' => [], 'owner' => ['OWNER_ID' => 'second-owner', 'owner' => null]]],
        ]], $mapped);
    }

    public function testExplicitRecordProjectionWinsWithoutResolvingEnvelopeDefaults(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->query(defaults: static function (): array {
                self::fail('An overridden default must not read native metadata.');
            })
            ->response(
                relations: ['children' => ['identifier' => 'CHILD_ID']],
                using: static fn (JsonResource $resource) => $resource->configureRecord(
                    static fn (Record $record) => $record->projectionUsing(static fn (): array => []),
                ),
            )->get('view', '/items/{id}');
        self::assertSame(['ITEM_ID' => 'root'], $this->map($builder, ['data' => ['id' => 'root', 'children' => [['id' => 'child']]]])['data']);
    }

    public function testSelectionRulesRequireExplicitNativeDefaults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selection rules require query(defaults: ...).');
        Strangler::proxy('catalog')->query(emptyFor: ['delete']);
    }

    public function testProjectionDoesNotLeakBetweenRecordsOrBuilds(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(
                optional: ['score'],
                projection: static fn (array $context): array => empty($context['query']) ? [] : ['children' => [], 'SCORE' => []],
            )
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID', 'name' => 'children', 'many' => true]])
            ->get('view', '/items/{id}');
        $json = json_decode('{"data":{"id":"root","score":7,"children":[{"id":"child","score":3}]}}');
        $first = $this->map($builder, $json);
        $second = $this->map($builder, $json, ['with' => ['children']]);

        self::assertSame(['ITEM_ID' => 'root'], $first['data']);
        self::assertSame(['ITEM_ID' => 'root', 'SCORE' => 7, 'children' => [['CHILD_ID' => 'child']]], $second['data']);
    }

    public function testRecordCallbacksDoNotLeakIntoPlainRelatedSchemas(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(
                values: ['name' => static fn (mixed $value): mixed => is_string($value) ? strtoupper($value) : $value],
                attributes: static fn (array $attributes, string $identifier): array => $attributes + ['kind' => $identifier],
                after: static fn (array $mapped, array $raw, ?array $projection, string $identifier, ?string $relation): array => $mapped + ['relation' => $relation],
            )
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID', 'name' => 'children', 'many' => true]])
            ->get('view', '/items/{id}');
        $result = $this->map($builder, json_decode('{"data":{"id":"root","name":"alice","children":[{"id":"child","name":"bob"}]}}'));

        self::assertSame([
            'ITEM_ID'  => 'root', 'NAME' => 'ALICE', 'KIND' => 'ITEM_ID',
            'children' => [['CHILD_ID' => 'child', 'NAME' => 'BOB']],
            'relation' => null,
        ], $result['data']);
    }

    public function testRoleOverridesKeepAssignmentMetadataWithoutChangingTheReferencedResource(): void
    {
        $controller = new RelatedGraphController('test');
        $filters = $controller->filters();
        $filter = end($filters);
        self::assertIsArray($filter);
        self::assertIsArray($filter['config']);
        $record = $filter['config']['responseRecord'];
        self::assertInstanceOf(Record::class, $record);
        self::assertSame(['CHILD_ID' => 'child'], $record(['id' => 'child', 'internal' => 'private']));
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(relations: [
            'children' => ['controller' => RelatedGraphController::class, 'except' => []],
        ])->get('view', '/items/{id}');
        self::assertSame(['ITEM_ID' => 'root', 'children' => [['CHILD_ID' => 'child', 'INTERNAL' => 'membership']]],
            $this->map($builder, ['data' => ['id' => 'root', 'children' => [['id' => 'child', 'internal' => 'membership']]]])['data']);
        self::assertSame(['CHILD_ID' => 'child'], $record(['id' => 'child', 'internal' => 'private']));
    }

    public function testResourceAliasesResolveBeforeSharedNullOmission(): void
    {
        $contract = new class extends ControllerContract
        {
            public function model(\CController $controller): \CActiveRecord
            {
                throw new \LogicException('Rendering must not query native models.');
            }

            public function payload(\CController $controller): array
            {
                return [];
            }

            public function responseAttributes(array $record, string $identifier, ?string $relation): array
            {
                return array_filter($record, static fn ($value): bool => $value !== null);
            }
        };
        $builder = Strangler::proxy('catalog')->using($contract)->uppercase('ITEM_ID')->response(
            attributes: static function (array $record): array {
                if (! array_key_exists('count', $record)) {
                    $record['count'] = $record['old_count'];
                }
                unset($record['old_count']);

                return $record;
            },
        )->get('view', '/items/{id}');
        self::assertSame(['ITEM_ID' => 'item'], $this->map($builder, ['data' => ['id' => 'item', 'count' => null, 'old_count' => 9]])['data']);
        self::assertSame(['ITEM_ID' => 'item', 'COUNT' => 0], $this->map($builder, ['data' => ['id' => 'item', 'count' => 0, 'old_count' => 9]])['data']);
    }

    public function testExportedRecordNeedsOnlyDeclaredMetadata(): void
    {
        $contract = new class extends ControllerContract
        {
            public function model(\CController $controller): \CActiveRecord
            {
                throw new \LogicException('A schema must not read the native model.');
            }

            public function payload(\CController $controller): array
            {
                throw new \LogicException('A schema must not read the request.');
            }
        };
        $config = Strangler::proxy('catalog')->using($contract)->uppercase('ITEM_ID')
            ->response(projection: static function (): array {
                throw new \LogicException('The exported record must not run root projection.');
            })->get('view', '/items/{id}')->build()['config'];

        $record = $config['responseRecord'] ?? null;
        self::assertNotNull($record);
        self::assertSame(['ITEM_ID' => 'root'], $record(['id' => 'root']));
    }

    public function testControllerRelationsAreLazyAndReuseTheirOwnRecursiveDeclarations(): void
    {
        RelatedGraphController::$builds = 0;
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(relations: [
                'children' => ['controller' => RelatedGraphController::class, 'name' => 'children', 'many' => true, 'fields' => ['name' => 'ROLE_LABEL'],
                    'after'                 => static fn (array $mapped): array => $mapped + ['role' => 'child']],
            ], projection: static fn (array $context): array => empty($context['query']) ? [] : ['children' => ['children' => []]])
            ->get('view', '/items/{id}');
        $json = json_decode('{"data":{"id":"root","children":[{"id":"child","name":"one","children":[{"id":"grandchild","name":"two"}]},{"id":"sibling"}]}}');

        self::assertSame(['ITEM_ID' => 'root'], $this->map($builder, $json)['data']);
        self::assertSame(0, RelatedGraphController::$builds);
        $mapped = $this->map($builder, $json, ['children' => ['children' => []]]);
        self::assertSame([
            'ITEM_ID' => 'root', 'children' => [
                ['CHILD_ID' => 'child', 'ROLE_LABEL' => 'one', 'children' => [['CHILD_ID' => 'grandchild', 'LABEL' => 'two']], 'role' => 'child'],
                ['CHILD_ID' => 'sibling', 'role' => 'child'],
            ],
        ], $mapped['data']);
        self::assertSame(2, RelatedGraphController::$builds);
    }

    public function testActionOptionsUseTheSameCaseInsensitiveNamesAsRoutes(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->query(defaults: [], extendFor: ['VIEW' => ['children']])->response(using: static fn (JsonResource $resource): JsonResource => $resource->rejectEmpty(['meta', 'added_ids'], 'duplicate', 409, ['VIEW']))
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID']])
            ->get('View', '/items/{id}');
        self::assertSame(['ITEM_ID' => 'root', 'children' => [['CHILD_ID' => 'child']]],
            $this->map($builder, ['data' => ['id' => 'root', 'children' => [['id' => 'child']]]])['data']);
        self::assertSame(['state' => 'error', 'error' => 'duplicate'],
            $this->map($builder, ['data' => ['id' => 'root'], 'meta' => ['added_ids' => []]]));
    }

    public function testSelectionSharesProjectionWithTheQueryBeforeApplicationAdaptation(): void
    {
        $seen = null;
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->query(defaults: static fn (): array => ['children' => []])
            ->query(using: static function (array $query, array $context, Query $defaults) use (&$seen): array|false {
                $seen = $query['with'];

                return $defaults($query, $context);
            })
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID', 'name' => 'children', 'many' => true]])
            ->get('view', '/items/{id}');
        $config = $builder->build()['config'];
        $queryMapper = $config['mappings']['query']['view'] ?? null;
        self::assertIsCallable($queryMapper);
        self::assertSame(['include' => 'children'], $queryMapper([], ['action' => 'view']));
        self::assertSame(['children'], $seen);
        self::assertSame(['ITEM_ID' => 'root', 'children' => [['CHILD_ID' => 'child']]], $this->map($builder, json_decode('{"data":{"id":"root","children":[{"id":"child"}]}}'))['data']);
    }

    public function testRequiredAttributesRejectMissingNullAndWrongTypesBeforeNormalizing(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(
            required: ['id' => 'string', 'flag' => 'bool', 'count' => 'int', 'rows' => 'array'],
        )->get('view', '/items/{id}');
        $valid = ['id' => 'root', 'flag' => false, 'count' => 0, 'rows' => []];
        self::assertSame(['ITEM_ID' => 'root', 'FLAG' => false, 'COUNT' => 0, 'ROWS' => []], $this->map($builder, ['data' => $valid])['data']);
        foreach ([array_diff_key($valid, ['flag' => true]), array_replace($valid, ['flag' => null]), array_replace($valid, ['id' => 1]), array_replace($valid, ['count' => '0'])] as $invalid) {
            self::assertSame(['state' => 'error', 'error' => 'invalid_upstream_response'], $this->map($builder, ['data' => $invalid]));
        }
    }

    public function testRequiredAttributesRejectUnsupportedTypesAtBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(required: ['id' => 'object'])->build();
    }

    public function testRequiredEnvelopeIsCheckedBeforeEmptyActionMapping(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(using: static fn (JsonResource $resource): JsonResource => $resource->requireData())
            ->response(using: static fn (JsonResource $resource) => $resource->empty(['view']))
            ->get('view', '/items/{id}');
        self::assertSame(['state' => 'error', 'error' => 'invalid_upstream_response'], $this->map($builder, ['meta' => []]));
        self::assertSame(['state' => 'data'], $this->map($builder, ['data' => null]));
        $config = $builder->build()['config'];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'view', 'query' => [], 'payload' => null]);
        self::assertSame('{"state":"data"}', (string) $mapper->response(new Response(204))->getBody());
    }

    public function testEmptyOperationResultsAreRejectedBeforeAnEmptySuccessEnvelope(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(using: static fn (JsonResource $resource): JsonResource => $resource->requireData())->response(using: static fn (JsonResource $resource): JsonResource => $resource->rejectEmpty(['meta', 'added_ids'], 'duplicate', 409, ['view']))
            ->response(using: static fn (JsonResource $resource) => $resource->empty(['view']))
            ->get('view', '/items/{id}');
        self::assertSame(['state' => 'error', 'error' => 'duplicate'], $this->map($builder, ['data' => null, 'meta' => ['added_ids' => []]]));
        self::assertSame(['state' => 'data'], $this->map($builder, ['data' => null, 'meta' => ['added_ids' => ['id']]]));
        self::assertSame(['state' => 'data'], $this->map($builder, ['data' => null]));
    }

    public function testEmptyResultRulesRejectInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(using: static fn (JsonResource $resource): JsonResource => $resource->rejectEmpty([], 'duplicate', 409))->build();
    }

    public function testRelatedPlainRecordKeepsItsIdTypeAndRoleAttributes(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('LOG_ID')->response(
            attributes: static function (array $record): array {
                self::assertIsString($record['id']);
                $record['id'] = (int) $record['id'];

                return $record;
            },
            after: static fn (array $mapped): array => $mapped + ['marker' => 'root'],
            relations: ['actor' => [
                'identifier' => 'ACTOR_ID', 'many' => false,
                'attributes' => static function (array $record): array {
                    self::assertIsBool($record['enabled']);
                    $record['enabled'] = (int) $record['enabled'];

                    return $record;
                },
            ]],
        )->get('view', '/logs/{id}');
        self::assertSame(['LOG_ID' => 1, 'actor' => ['ACTOR_ID' => '5', 'ENABLED' => 0], 'marker' => 'root'],
            $this->map($builder, json_decode('{"data":{"id":"1","actor":{"id":"5","enabled":false}}}'))['data']);
    }

    public function testRelationConfigurationRejectsMissingAndCompetingSources(): void
    {
        foreach ([[], ['identifier' => 'ITEM_ID', 'controller' => RelatedGraphController::class]] as $relation) {
            try {
                Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(relations: ['child' => $relation])->build();
                self::fail('An ambiguous relation source was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('exactly one identifier or controller', $exception->getMessage());
            }
        }
    }

    public function testRelatedSourceMustBeAYiiController(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(relations: ['children' => \stdClass::class])
            ->get('view', '/items/{id}');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Related controller must extend CController.');
        $this->map($builder, ['data' => ['id' => 'root', 'children' => [['id' => 'child']]]]);
    }

    public function testOperationErrorRulesDoNotChangeAnotherActionsSuccessfulResponse(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(using: static fn (JsonResource $resource): JsonResource => $resource->rejectEmpty(['meta', 'added_ids'], 'duplicate', 409, ['attach']))->get('view', '/items/{id}');
        self::assertSame(['state' => 'data', 'data' => ['ITEM_ID' => 'item']],
            $this->map($builder, ['data' => ['id' => 'item'], 'meta' => ['added_ids' => []]]));
    }

    public function testOperationErrorConfigurationRejectsEmptyNamesAndSuccessfulStatuses(): void
    {
        foreach ([
            [['meta', ''], 'duplicate', 409, []],
            [['meta'], 'duplicate', 409, ['']],
            [['meta'], 'duplicate', 399, []],
            [['meta'], 'duplicate', 600, []],
            [['meta'], '', 409, []],
        ] as [$path, $error, $status, $actions]) {
            try {
                Strangler::proxy('catalog')->uppercase('ITEM_ID')->response(using: static fn (JsonResource $resource): JsonResource => $resource->rejectEmpty($path, $error, $status, $actions))->build();
                self::fail('Invalid operation result configuration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Invalid', $exception->getMessage());
            }
        }
    }

    public function testControllerRelationRequiresExactlyOneResponseDeclaration(): void
    {
        foreach ([MissingGraphController::class, AmbiguousGraphController::class] as $controller) {
            $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
                ->response(relations: ['child' => ['controller' => $controller, 'name' => 'child', 'many' => false]])
                ->get('view', '/items/{id}');

            try {
                $this->map($builder, json_decode('{"data":{"id":"root","child":{"id":"child"}}}'));
                self::fail('Missing or ambiguous declaration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('exactly one Strangler response record', $exception->getMessage());
            }
        }
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    private function map(Strangler $builder, mixed $json, array $query = []): array
    {
        $config = $builder->build()['config'];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'view', 'query' => $query, 'payload' => null]);
        $response = $mapper->response(new Response(200, [], json_encode($json, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

class RelatedGraphController extends \CController
{
    public static int $builds = 0;

    public function init(): void
    {
        throw new \LogicException('Schema resolution must not initialize a controller.');
    }

    /** @return list<mixed> */
    public function filters(): array
    {
        self::$builds++;

        return ['accessControl', ['nativeFilter'], Strangler::proxy('catalog')->uppercase('CHILD_ID')
            ->response(fields: ['name' => 'LABEL'], except: ['internal'], projection: static fn (): array => [], relations: [
                'children' => self::class,
            ])->get('view', '/children/{id}')->build()];
    }
}

class MissingGraphController extends \CController
{
    /** @return list<mixed> */
    public function filters(): array
    {
        return [];
    }
}

class AmbiguousGraphController extends RelatedGraphController
{
    /** @return list<mixed> */
    public function filters(): array
    {
        return [...parent::filters(), ...parent::filters()];
    }
}
