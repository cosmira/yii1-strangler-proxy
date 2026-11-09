<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CActiveRecord;
use CController;
use CDbCriteria;
use CDbTableSchema;
use CFilter;
use CFilterChain;
use Cosmira\Strangler\ControllerContract;
use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerFilter;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\NativeControllerContract;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yii;

final class RuntimeBindingTest extends TestCase
{
    private Controller $controller;

    private WebApplication $application;

    protected function setUp(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $this->application = new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
            'params' => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['example' => true]]],
        ]);
        $this->application->shouldEnd = false;
        $this->controller = new Controller('example');
    }

    protected function tearDown(): void
    {
        Yii::setApplication(null);
        AutoBoundRelatedController::$native = null;
        $_GET = [];
    }

    public function testNativeIdentifierIsResolvedAfterPrecedingYiiFilters(): void
    {
        $scope = new class extends CFilter
        {
            public bool $applied = false;

            protected function preFilter(mixed $filterChain): bool
            {
                $this->applied = true;

                return true;
            }
        };
        $model = $this->createMock(CActiveRecord::class);
        $model->expects(self::never())->method('getTableSchema');
        $model->expects(self::once())->method('primaryKey')->willReturnCallback(static function () use ($scope): string {
            self::assertTrue($scope->applied);

            return 'NATIVE_ID';
        });
        $contract = new NativeControllerContract($model);
        $filter = Strangler::proxy('example')->using($contract)
            ->response(fields: ['name' => 'TITLE'])->get('get', '/items')->build();
        self::assertSame(0, $contract->models);
        self::assertSame(0, $contract->reads);
        $filter['client'] = new Client(['handler' => new MockHandler([
            new Response(200, [], '{"data":[{"id":"item","name":"Desk"}],"meta":{"total":1}}'),
        ])]);
        $chain = CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter]);
        $chain->insertAt(0, $scope);
        ob_start();

        try {
            $chain->run();
            self::assertSame(['state' => 'data', 'data' => [['NATIVE_ID' => 'item', 'TITLE' => 'Desk']], 'meta' => ['totalCount' => 1]], json_decode((string) ob_get_contents(), true));
        } finally {
            ob_end_clean();
        }
        self::assertSame(1, $contract->models);
        self::assertSame(1, $contract->reads);
        self::assertFalse($this->controller->ran);
    }

    public function testDatabaseMetadataResolvesTheStandardNullOverrideOnlyAtRuntime(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = 'TABLE_ID';
        $model->expects(self::once())->method('getTableSchema')->willReturn($schema);
        $contract = new NativeControllerContract($model);
        $config = Strangler::proxy('example')->using($contract)->get('get', '/items')->build()['config'];
        self::assertSame(0, $contract->models);
        $bound = ControllerContract::resolveConfig($config, $this->controller);
        self::assertSame('TABLE_ID', $bound['bodyIdentifier'] ?? null);
        self::assertSame(1, $contract->models);
    }

    public function testDatabaseMetadataKeysAreSharedByConflictAndScopedParentLookup(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = 'TABLE_ID';
        $model->method('getTableSchema')->willReturn($schema);
        $criteria = new CDbCriteria(['condition' => 'scope = 1']);
        $model->method('getDbCriteria')->willReturn($criteria);
        $model->method('setDbCriteria')->willReturnCallback(static function (CDbCriteria $copy) use ($criteria): void {
            self::assertNotSame($criteria, $copy);
            self::assertSame('scope = 1', $copy->condition);
        });
        $record = $this->createMock(CActiveRecord::class);
        $record->method('__get')->with('PARENT_ID')->willReturn('parent');
        $model->expects(self::once())->method('findByPk')->with('item')->willReturn($record);
        $contract = new NativeControllerContract($model);
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', ['id' => 'item'], ['TABLE_ID' => 'other']));
        self::assertFalse($contract->shouldBypass($this->controller, 'edit', ['id' => 'item'], ['TABLE_ID' => 'item']));
        self::assertSame(['parent_id' => 'parent'], $contract->parentParameters($this->controller, 'PARENT_ID', [], ['TABLE_ID' => 'item'], null));
    }

    public function testDatabaseMetadataKeyIsAvailableToNativeErrorFieldAliases(): void
    {
        $this->application->setController($this->controller);
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = 'TABLE_ID';
        $model->method('getTableSchema')->willReturn($schema);
        $contract = new class($model) extends ControllerContract
        {
            public function __construct(private readonly CActiveRecord $native) {}

            public function model(CController $controller): CActiveRecord
            {
                return $this->native;
            }

            public function payload(CController $controller): array
            {
                return [];
            }

            public function responseFields(string $identifier): array
            {
                TestCase::assertSame('TABLE_ID', $identifier);

                return ['name' => 'TITLE'];
            }
        };
        $response = $contract->error(json_decode('{"errors":{"name":["Required"]}}'), new Response(422), ['action' => 'get']);
        self::assertSame(['state' => 'error', 'error' => 'validation', 'meta' => ['TITLE' => ['Required' => []]]], json_decode((string) $response->getBody(), true));
    }

    /** @param array<array-key, string>|string|null $key */
    #[DataProvider('invalidSchemaKeys')]
    public function testDatabaseMetadataRejectsCompositeOrMissingKeys(array|string|null $key): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = $key;
        $model->method('getTableSchema')->willReturn($schema);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires one native identifier');
        ControllerContract::modelKey($model);
    }

    /** @return list<array{mixed}> */
    public static function invalidSchemaKeys(): array
    {
        return [[['FIRST_ID', 'SECOND_ID']], [null], ['']];
    }

    #[DataProvider('unhandledCases')]
    public function testUnselectedAndEarlyBypassedRequestsNeverReadModels(string $kind): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $model->expects(self::never())->method('primaryKey');
        $contract = new NativeControllerContract($model);
        $builder = Strangler::proxy('example')->using($contract)
            ->get($kind === 'unmapped' ? 'view' : 'get', '/items');
        if ($kind === 'disabled') {
            $this->application->getParams()->add('strangler', ['features' => ['example' => false]]);
        }
        if ($kind === 'bypassed') {
            $builder->bypassUsing(static fn (): bool => true);
        }
        $filter = $builder->build();
        $filter['client'] = new Client(['handler' => static function (): never {
            self::fail('An unselected request must not reach HTTP.');
        }]);
        CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter])->run();
        self::assertTrue($this->controller->ran);
        self::assertSame(0, $contract->models);
        self::assertSame(0, $contract->reads);
    }

    /** @return list<array{string}> */
    public static function unhandledCases(): array
    {
        return [['disabled'], ['unmapped'], ['bypassed']];
    }

    public function testDirectDeferredBypassPreservesReadersAndStructuralGuards(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = 'TABLE_ID';
        $model->method('getTableSchema')->willReturn($schema);
        $contract = new NativeControllerContract($model);
        $reads = 0;
        $config = Strangler::proxy('example')->using($contract)
            ->request(
                nativeOn: ['LEGACY'], required: ['TABLE_ID' => 'identifier'],
                reader: static function () use (&$reads): array {
                    $reads++;

                    return ['LEGACY' => true];
                },
                actions: ['edit'],
            )
            ->bypassUsing(static fn (CController $controller, string $action, array $query): bool => isset($query['early']))
            ->put('edit', '/items/{id}')->build()['config'];
        $_GET = ['early' => true];
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit'));
        self::assertSame(0, $contract->models);
        self::assertSame(0, $reads);
        $_GET = [];
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit', ['LEGACY' => true]));
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'edit', ['TABLE_ID' => 'item']));
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit', []));
        self::assertSame(0, $reads);
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit'));
        self::assertSame(1, $reads);
        self::assertSame(4, $contract->models);
    }

    public function testDeferredCompilerSnapshotsAreIsolatedAcrossBuildsAndRequests(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $model->method('primaryKey')->willReturnOnConsecutiveCalls('FIRST_ID', 'SECOND_ID');
        $contract = new NativeControllerContract($model);
        $builder = Strangler::proxy('example')->using($contract)
            ->response(fields: ['name' => 'TITLE'])->get('view', '/items/{id}');
        $config = $builder->build()['config'];
        $builder->response(fields: ['name' => 'OTHER_TITLE'])->get('extra', '/other');
        $first = ControllerContract::resolveConfig($config, $this->controller);
        $second = ControllerContract::resolveConfig($config, $this->controller);
        self::assertSame('FIRST_ID', $first['bodyIdentifier'] ?? null);
        self::assertSame('SECOND_ID', $second['bodyIdentifier'] ?? null);
        self::assertArrayNotHasKey('extra', $first['routes'] ?? []);
        self::assertArrayNotHasKey('bodyIdentifier', $config);
        $mapper = new InlineMapping($first['mappings'] ?? [], ['action' => 'view', 'query' => [], 'payload' => null]);
        self::assertSame(['state' => 'data', 'data' => ['FIRST_ID' => 'item', 'TITLE' => 'Desk']], json_decode((string) $mapper->response(new Response(200, [], '{"data":{"id":"item","name":"Desk"}}'))->getBody(), true));
        self::assertSame(2, $contract->models);
    }

    public function testExcludedPhotosKeepExplicitStageCallbacksAfterNativeBinding(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $model->method('primaryKey')->willReturn('PERSON_ID');
        $contract = new NativeControllerContract($model);
        $config = Strangler::proxy('example')->using($contract, except: ['image'])
            ->mapQuery(['person_id' => 'photo_owner'], ['image'])
            ->get('view', '/people/{id}')->get('image', '/people/{person_id}/photo')->build()['config'];
        $bound = ControllerContract::resolveConfig($config, $this->controller);
        $mapper = new InlineMapping($bound['mappings'] ?? [], ['action' => 'image', 'query' => [], 'payload' => null]);
        $response = new Response(200, ['Content-Type' => 'image/jpeg'], 'binary photo');
        self::assertSame($response, $mapper->response($response));
        $request = $mapper->request(['person_id' => 'owner'], ['PERSON_ID' => 'body'], 'original');
        self::assertNotFalse($request);
        self::assertSame(['photo_owner' => 'owner'], $request['query']);
        self::assertSame(['PERSON_ID' => 'body'], $request['payload']);
    }

    public function testRelatedDeferredDeclarationsUseTheirOwnNativeModelKeys(): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $model->expects(self::exactly(2))->method('primaryKey')->willReturn('RELATED_ID');
        AutoBoundRelatedController::$native = $model;
        $config = Strangler::proxy('example')->uppercase('ROOT_ID')->response(relations: [
            'children' => AutoBoundRelatedController::class,
        ])->get('view', '/items/{id}')->build()['config'];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'view', 'query' => [], 'payload' => null]);
        $response = $mapper->response(new Response(200, [], '{"data":{"id":"root","children":[{"id":"child","children":[{"id":"leaf"}]}]}}'));
        self::assertSame(['state' => 'data', 'data' => ['ROOT_ID' => 'root', 'children' => [['RELATED_ID' => 'child', 'children' => [['RELATED_ID' => 'leaf']]]]]], json_decode((string) $response->getBody(), true));
    }

    public function testMalformedRelatedDeclarationsAreRejectedWithoutRunningControllers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one Strangler response record');
        ControllerContract::responseRecord(MalformedBoundController::class);
    }

    #[DataProvider('invalidKeys')]
    public function testUnsupportedNativeKeysFailBeforePayloadOrHttp(mixed $key): void
    {
        $model = $this->createMock(CActiveRecord::class);
        $model->method('primaryKey')->willReturn($key);
        $model->method('getTableSchema')->willReturn(new CDbTableSchema);
        $contract = new NativeControllerContract($model);
        $config = Strangler::proxy('example')->using($contract)->get('get', '/items')->build()['config'];
        $proxy = new StranglerProxy(new Client(['handler' => static function (): never {
            self::fail('An invalid native key must fail before HTTP.');
        }]));

        try {
            $proxy->handle($this->controller, $config, 'get');
            self::fail('An invalid native key was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Conventional mapping requires one native identifier.', $exception->getMessage());
        }
        self::assertSame(1, $contract->models);
        self::assertSame(0, $contract->reads);
    }

    /** @return list<array{mixed}> */
    public static function invalidKeys(): array
    {
        return [[['FIRST_ID', 'SECOND_ID']], [null], ['']];
    }
}

final class AutoBoundRelatedController extends CController
{
    public static ?CActiveRecord $native = null;

    public function init(): void
    {
        TestCase::fail('Related schema binding must not initialize its controller.');
    }

    /** @return list<array{0: class-string, config: array<array-key, mixed>}> */
    public function filters(): array
    {
        $contract = new class extends ControllerContract
        {
            public function model(CController $controller): CActiveRecord
            {
                TestCase::assertNotNull(AutoBoundRelatedController::$native);

                return AutoBoundRelatedController::$native;
            }

            public function payload(CController $controller): array
            {
                TestCase::fail('Related schema binding must not read a payload.');
            }
        };

        return [Strangler::proxy('example')->using($contract)->response(relations: [
            'children' => self::class,
        ])->get('view', '/related/{id}')->build()];
    }
}

final class MalformedBoundController extends CController
{
    /** @return list<mixed> */
    public function filters(): array
    {
        return [
            [StranglerFilter::class],
            [StranglerFilter::class, 'config' => ['resolve' => static fn (): bool => false]],
        ];
    }
}
