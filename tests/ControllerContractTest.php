<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CActiveRecord;
use CController;
use CDbCriteria;
use CDbTableSchema;
use Cosmira\Strangler\ControllerContract;
use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\NativeControllerContract;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Yii;

/**
 * @phpstan-import-type Config from Strangler
 */
final class ControllerContractTest extends TestCase
{
    private CController $controller;

    protected function setUp(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $application = new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
            'components'  => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
            'params' => ['strangler' => ['base_uri' => 'https://backend.test']],
        ]);
        $application->shouldEnd = false;
        $this->controller = new Controller('example');
    }

    protected function tearDown(): void
    {
        Yii::setApplication(null);
        $_GET = [];
    }

    public function testNativeRelationWritesStayLocalUnlessExplicitlyAdaptedOrConsumed(): void
    {
        $contract = new class extends ControllerContract
        {
            /** @var array<array-key, mixed> */
            public array $body = [];

            public int $relationReads = 0;

            public function model(CController $controller): CActiveRecord
            {
                throw new \LogicException('An explicit identifier must not inspect a model.');
            }

            public function payload(CController $controller): array
            {
                return $this->body;
            }

            public function nativeRelations(CController $controller): array
            {
                $this->relationReads++;

                return ['children', 'groups', 'contacts', 'tags'];
            }
        };
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')
            ->request(relations: ['CONTACTS'])
            ->request(relations: ['CHILDREN'], actions: ['Create'])
            ->request(relations: ['tags'], actions: ['create'])
            ->post('create', '/api/groups/{group_id}/items', fromBody: ['group_id' => 'GROUPS.*.GROUP_ID'])
            ->put('edit', '/api/items/{id}')
            ->put('link', '/api/items/{id}/children')
            ->build()['config'];
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $proxy = new StranglerProxy($client);
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        foreach ([[], [['id' => 'child']], '[]', null, 'invalid'] as $value) {
            foreach (['CHILDREN', 'children', 'ChIlDrEn'] as $field) {
                $contract->body = [$field => $value];
                self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit'));
                self::assertFalse($proxy->handle($this->controller, $config, 'edit'));
            }
        }
        foreach ([['CONTACTS' => []], ['FUTURE_FIELD' => 'value'], []] as $body) {
            self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'edit', $body));
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $group = ['GROUPS' => [['GROUP_ID' => 'g']]];
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'create', $group + [
            'CHILDREN' => [], 'CONTACTS' => [], 'TAGS' => [],
        ]));
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'create', ['GROUPS' => []]));
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'create', [
            'GROUPS' => [['GROUP_ID' => 'g', 'NOTE' => 'must not be dropped']],
        ]));
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'edit', ['TAGS' => []]));
        $reads = $contract->relationReads;
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'link', ['CHILDREN' => []]));
        self::assertSame($reads, $contract->relationReads);
    }

    public function testAdaptedRelationsRequireNonemptyNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Adapted relation names must be nonempty.');

        Strangler::proxy('example')->request(relations: [' ']);
    }

    public function testNativeChecksRunOnceAfterStructuralGuardsBeforeMapping(): void
    {
        $_GET = ['id' => 'item'];
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $payload = ['ITEM_ID' => [], 'PARENT_ID' => 'parent', 'META' => (object) ['opaqueKey' => 'original']];
        $reads = 0;
        $seen = [];
        $bodies = [];
        $config = Strangler::proxy('example')->uppercase('ITEM_ID')
            ->request(
                reader: static function () use (&$payload, &$reads): array {
                    $reads++;

                    return $payload;
                },
                required: ['ITEM_ID' => 'identifier', 'PARENT_ID' => 'identifier'],
                before: static function (array $original, string $action, CController $controller) use (&$seen): void {
                    $seen[] = [$original['PARENT_ID'], $action, $controller];
                    $original['PARENT_ID'] = 'must not reach upstream';
                    self::assertInstanceOf(\stdClass::class, $original['META']);
                    $original['META']->opaqueKey = 'must not reach upstream';
                },
                actions: ['Move'],
            )
            ->request(before: static function (): void {
                self::fail('Another action check must not run.');
            }, actions: ['other'])
            ->put('move', '/api/items/{id}/parents')->build()['config'];
        $client = new Client(['handler' => static function (RequestInterface $request) use (&$bodies) {
            $bodies[] = json_decode((string) $request->getBody(), true);

            return new FulfilledPromise(new Response(204));
        }]);
        $proxy = new StranglerProxy($client);
        self::assertFalse($proxy->handle($this->controller, $config, 'move'));
        self::assertSame([], $seen);
        self::assertSame([], $bodies);
        $payload['ITEM_ID'] = 'item';
        ob_start();

        try {
            self::assertTrue($proxy->handle($this->controller, $config, 'move'));
        } finally {
            ob_end_clean();
        }
        self::assertSame([['parent', 'move', $this->controller]], $seen);
        self::assertSame('original', $payload['META']->opaqueKey);
        self::assertSame([['parent_id' => 'parent', 'meta' => ['opaqueKey' => 'original']]], $bodies);
        self::assertSame(2, $reads);
    }

    public function testNativeGuardConfigurationRejectsBlankNamesAndInvalidMethods(): void
    {
        foreach ([[''], [' ']] as $names) {
            try {
                Strangler::proxy('example')->request(nativeOn: $names);
                self::fail('An empty native field was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('nonempty', $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('uppercase HTTP methods');
        Strangler::proxy('example')->request(methods: ['put bad']);
    }

    public function testDeclarativeRequestGuardsComposeAcrossActionsAndKeepPayloadUntouched(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $config = Strangler::proxy('example')->uppercase('ITEM_ID')
            ->request(bypass: static fn (array $payload): bool => isset($payload['EXPLICIT']))
            ->request(nativeOn: ['UNSUPPORTED'], actions: ['Move', 'SetStatus'])
            ->request(required: ['ITEM_ID' => 'identifier', 'PARENT_ID' => 'identifier'], methods: ['put', 'Post'], actions: ['MOVE'])
            ->request(required: ['ROWS' => 'rows'], actions: ['setstatus'])
            ->put('move', '/api/items/{id}')->put('setstatus', '/api/items/{id}/statuses')->build()['config'];
        $bypass = function (array $payload, string $action, array $query) use ($config): bool {
            $_GET = $query;

            return StranglerProxy::shouldBypass($this->controller, $config, $action, $payload);
        };
        $payload = ['PARENT_ID' => 0];
        self::assertFalse($bypass($payload, 'move', ['id' => 'item']));
        self::assertSame(['PARENT_ID' => 0], $payload);
        self::assertTrue($bypass(['PARENT_ID' => false], 'move', ['id' => 'item']));
        self::assertTrue($bypass(['PARENT_ID' => null], 'move', ['id' => 'item']));
        self::assertTrue($bypass([], 'move', ['id' => 'item']));
        self::assertTrue($bypass(['ITEM_ID' => 'other', 'PARENT_ID' => 'parent'], 'move', ['id' => 'item']));
        self::assertTrue($bypass(['ITEM_ID' => [], 'PARENT_ID' => 'parent'], 'move', ['id' => 'item']));
        self::assertTrue($bypass(['unsupported' => true], 'setstatus', []));
        self::assertFalse($bypass(['unsupported' => true], 'view', []));
        self::assertTrue($bypass(['EXPLICIT' => true], 'view', []));
        self::assertFalse($bypass(['ROWS' => ['named' => ['id' => 1]]], 'setstatus', []));
        self::assertFalse($bypass(['ROWS' => []], 'setstatus', []));
        self::assertTrue($bypass(['ROWS' => [1]], 'setstatus', []));
        self::assertTrue($bypass(['ROWS' => null], 'setstatus', []));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue($bypass(['PARENT_ID' => 'parent'], 'move', ['id' => 'item']));
    }

    public function testRequestGuardsRejectUnsupportedConfigurationBeforeBuilding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('example')->request(required: ['ITEM_ID' => 'object']);
    }

    public function testControllerContractReadsPayloadAndChecksFinalMappedResponse(): void
    {
        $model = $this->finder();
        $model->expects(self::never())->method('findByPk');
        $contract = $this->contract($model);
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')
            ->post('create', '/api/items')->build()['config'];
        $this->forward($config);
        self::assertSame(1, $contract->reads);
        self::assertInstanceOf(ResponseInterface::class, $contract->validated);
        self::assertSame('{"state":"data","data":{"ITEM_ID":"item"}}', (string) $contract->validated->getBody());
    }

    public function testExplicitPayloadReaderWinsRegardlessOfRegistrationOrder(): void
    {
        $contract = $this->contract($this->finder());
        $config = Strangler::proxy('example')
            ->request(reader: static fn (): array => ['DISPLAY_NAME' => 'Custom'])
            ->using($contract)->uppercase('ITEM_ID')->post('create', '/api/items')->build()['config'];
        $this->forward($config);
        self::assertSame(0, $contract->reads);
    }

    public function testWithinDoesNotReadTheModelForRoutesWithoutItsPlaceholder(): void
    {
        $contract = $this->contract($this->finder());
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')
            ->within('PARENT_ID')->post('create', '/api/items')->build()['config'];
        $this->forward($config);
        self::assertSame(0, $contract->models);
    }

    public function testWithinUsesScopedFinderAndPreservesExplicitRouteParameters(): void
    {
        $_GET = ['id' => 'item'];
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $model = $this->finder();
        $record = $this->createMock(CActiveRecord::class);
        $record->method('__get')->with('PARENT_ID')->willReturn('parent');
        $model->expects(self::once())->method('findByPk')->with('item')->willReturn($record);
        $contract = $this->contract($model);
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')->within('PARENT_ID')
            ->request(parameters: static fn (): array => ['extra' => 'ok'])
            ->put('edit', '/api/{extra}/parents/{parent_id}/items/{id}')->build()['config'];
        $path = $this->forward($config);
        self::assertSame('/api/ok/parents/parent/items/item', $path);
        self::assertSame(1, $contract->models);
    }

    public function testOutOfScopeRecordDoesNotSendAnUpstreamRequest(): void
    {
        $_GET = ['id' => 'missing'];
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $model = $this->finder();
        $model->expects(self::once())->method('findByPk')->with('missing')->willReturn(null);
        $config = Strangler::proxy('example')->using($this->contract($model))->uppercase('ITEM_ID')
            ->within('PARENT_ID')->put('edit', '/api/parents/{parent_id}/items/{id}')->build()['config'];
        $proxy = new StranglerProxy(new Client(['handler' => new MockHandler([])]));
        self::assertFalse($proxy->handle($this->controller, $config, 'edit'));
    }

    public function testParentConflictDoesNotSendAnUpstreamRequest(): void
    {
        $_GET = ['id' => 'item'];
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $model = $this->finder();
        $record = $this->createMock(CActiveRecord::class);
        $record->method('__get')->willReturn('parent');
        $model->method('findByPk')->willReturn($record);
        $contract = $this->contract($model);
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')->within('PARENT_ID')
            ->request(parameters: static fn (): array => ['parent_id' => 'other'])
            ->put('edit', '/api/parents/{parent_id}/items/{id}')->build()['config'];
        $proxy = new StranglerProxy(new Client(['handler' => new MockHandler([])]));
        self::assertFalse($proxy->handle($this->controller, $config, 'edit'));
    }

    public function testWithinRequiresAnApplicationContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('example')->within('PARENT_ID')->build();
    }

    public function testParentResolutionRejectsInvalidOrConflictingNativeValues(): void
    {
        $model = $this->finder();
        $record = $this->createMock(CActiveRecord::class);
        $record->method('__get')->willReturn('parent');
        $model->method('findByPk')->willReturn($record);
        $contract = $this->contract($model);
        self::assertFalse($contract->parentParameters($this->controller, 'PARENT_ID', ['id' => []], [], 'ITEM_ID'));
        self::assertFalse($contract->parentParameters($this->controller, 'PARENT_ID', ['id' => 'item'], ['PARENT_ID' => 'other'], 'ITEM_ID'));
        self::assertFalse($contract->parentParameters($this->controller, 'PARENT_ID', [], [], 'ITEM_ID'));
        self::assertFalse($contract->parentParameters($this->controller, 'PARENT_ID', [], ['PARENT_ID' => ''], 'ITEM_ID'));
        self::assertSame(['parent_id' => 'new-parent'], $contract->parentParameters(
            $this->controller, 'PARENT_ID', [], ['PARENT_ID' => 'new-parent'], 'ITEM_ID',
        ));
    }

    public function testParentLookupCanUseTheNativePrimaryKey(): void
    {
        $model = $this->finder();
        $model->method('primaryKey')->willReturn('ITEM_ID');
        $record = $this->createMock(CActiveRecord::class);
        $record->method('__get')->willReturn('parent');
        $model->expects(self::once())->method('findByPk')->with('body-item')->willReturn($record);
        self::assertSame(['parent_id' => 'parent'], $this->contract($model)->parentParameters(
            $this->controller, 'PARENT_ID', [], ['ITEM_ID' => 'body-item'], null,
        ));
    }

    public function testCompositeNativeKeysRequireAnExplicitIdentifier(): void
    {
        $model = $this->finder();
        $model->method('primaryKey')->willReturn(['ITEM_ID', 'OTHER_ID']);
        $this->expectException(InvalidArgumentException::class);
        $this->contract($model)->parentParameters($this->controller, 'PARENT_ID', [], [], null);
    }

    public function testDeclaredIdentifierEnablesConventionsWithoutReadingTheNativeModel(): void
    {
        $contract = new class extends ControllerContract
        {
            public function identifier(): string
            {
                return 'ITEM_ID';
            }

            public function model(CController $controller): CActiveRecord
            {
                throw new \LogicException('Building filters must not read native metadata.');
            }

            public function payload(CController $controller): array
            {
                return [];
            }
        };
        foreach ([
            [Strangler::proxy('example')->using($contract), 'ITEM_ID'],
            [Strangler::proxy('example')->using($contract, except: ['IMAGE']), 'ITEM_ID'],
            [Strangler::proxy('example')->uppercase('OTHER_ID')->using($contract), 'OTHER_ID'],
            [Strangler::proxy('example')->using($contract)->uppercase(), 'ITEM_ID'],
            [Strangler::proxy('example')->uppercase()->using($contract), 'ITEM_ID'],
        ] as [$builder, $identifier]) {
            $config = $builder->get('view', '/items/{id}')->build()['config'];
            self::assertSame($identifier, $config['bodyIdentifier'] ?? null);
            $mapping = new InlineMapping($config['mappings'] ?? [], ['action' => 'view', 'query' => [], 'payload' => null]);
            $request = $mapping->request(['filter' => ['DISPLAY_NAME' => 'Desk']], [$identifier => 'item', 'DISPLAY_NAME' => 'Desk'], null);
            self::assertIsArray($request);
            self::assertSame(['display_name' => 'Desk'], $request['query']['filter']);
            self::assertIsString($request['body']);
            self::assertSame(['display_name' => 'Desk'], json_decode($request['body'], true));
            $response = $mapping->response(new Response(200, [], '{"data":{"id":"item","display_name":"Desk"}}'));
            self::assertSame(['state' => 'data', 'data' => [$identifier => 'item', 'DISPLAY_NAME' => 'Desk']],
                json_decode((string) $response->getBody(), true));
        }
        foreach ([
            Strangler::proxy('example')->using($contract, except: ['IMAGE']),
            Strangler::proxy('example')->uppercase(except: ['image'])->using($contract),
            Strangler::proxy('example')->using($contract, except: ['image'])->uppercase('OTHER_ID'),
        ] as $builder) {
            $config = $builder->get('image', '/items/{id}/image')->build()['config'];
            $mapping = new InlineMapping($config['mappings'] ?? [], ['action' => 'image', 'query' => [], 'payload' => null]);
            $response = new Response(200, ['Content-Type' => 'image/png'], 'binary');
            self::assertSame($response, $mapping->response($response));
        }
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        self::assertFalse($contract->shouldBypass($this->controller, 'edit', ['id' => 'item'], ['DISPLAY_NAME' => 'Name']));
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', ['id' => 'item'], ['ITEM_ID' => 'other']));
    }

    public function testConventionWithoutAnyIdentifierFailsAtConfigurationTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a native identifier');

        Strangler::proxy('example')->uppercase()->get('get', '/items')->build();
    }

    public function testDefaultApplicationHooksDoNotChangeResponsesOrErrors(): void
    {
        $model = $this->finder();
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
        };
        self::assertNull($contract->identifier());
        self::assertSame([], $contract->relations());
        self::assertSame([], $contract->responseFields('ITEM_ID'));
        self::assertSame(['name' => 'Name'], $contract->responseAttributes(['name' => 'Name'], 'ITEM_ID', null));
        self::assertSame(['NAME' => 'Name'], $contract->responseAfter(['NAME' => 'Name'], [], null, 'ITEM_ID', null));
        self::assertNull($contract->responseProjection([]));
        self::assertNull($contract->responseResult(null, new Response(), []));
        self::assertNull($contract->validationErrors($this->controller, 'create', []));
        $response = new Response(200, [], 'unchanged');
        $contract->validateResponse($response);
        self::assertSame('unchanged', (string) $response->getBody());
    }

    public function testWithinRejectsAnEmptyNativeAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('example')->within('');
    }

    public function testNativeValidationUsesTheOriginalSnapshotWithoutReadingOrSavingAgain(): void
    {
        StranglerProxy::application()->setController($this->controller);
        $model = $this->finder();
        $model->method('primaryKey')->willReturn('ITEM_ID');
        $contract = new class($model) extends ControllerContract
        {
            public function __construct(private readonly CActiveRecord $native) {}

            public function model(CController $controller): CActiveRecord
            {
                return $this->native;
            }

            public function payload(CController $controller): array
            {
                throw new \LogicException('The payload snapshot must be reused.');
            }

            public function responseFields(string $identifier): array
            {
                return ['display_name' => 'DISPLAY_NAME'];
            }

            public function validationErrors(CController $controller, string $action, array $payload): array
            {
                TestCase::assertSame('create', $action);
                TestCase::assertSame(['DISPLAY_NAME' => ''], $payload);

                return ['state' => 'error', 'error' => 'validation', 'meta' => ['DISPLAY_NAME' => ['required' => []]]];
            }
        };
        $response = $contract->error((object) ['errors' => (object) ['display_name' => ['Translated message']]], new Response(422), [
            'action' => 'create', 'payload' => ['DISPLAY_NAME' => ''],
        ]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('required', (string) $response->getBody());
        self::assertStringNotContainsString('Translated message', (string) $response->getBody());
    }

    public function testConventionalCrudGuardsDoNotFilterBackendCapabilities(): void
    {
        $model = $this->finder();
        $model->method('primaryKey')->willReturn('ITEM_ID');
        $contract = $this->contract($model);
        foreach (['get' => 'GET', 'view' => 'GET', 'create' => 'POST', 'edit' => 'PUT', 'delete' => 'DELETE'] as $action => $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            self::assertFalse($contract->shouldBypass($this->controller, $action, ['filter' => ['UNKNOWN' => 1]], []));
        }
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        self::assertFalse($contract->shouldBypass($this->controller, 'custom', ['filter' => false], ['list']));
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', [], []));
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', ['filter' => false], []));
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', [], ['list']));
        self::assertTrue($contract->shouldBypass($this->controller, 'edit', ['id' => 'query-id'], ['ITEM_ID' => 'body-id']));
        self::assertFalse($contract->shouldBypass($this->controller, 'edit', ['id' => 'same'], ['ITEM_ID' => 'same']));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue($contract->shouldBypass($this->controller, 'get', ['id' => 'item'], []));
    }

    public function testOrdinaryUpstreamFailureUsesTheSharedNativeErrorEnvelope(): void
    {
        $contract = $this->contract($this->finder());
        $response = $contract->error((object) ['message' => 'Missing item'], new Response(404),
            ['action' => 'view', 'query' => [], 'payload' => null]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['state' => 'error', 'error' => 'not_found'],
            json_decode((string) $response->getBody(), true));
        self::assertSame(0, $contract->models);
        self::assertSame(0, $contract->reads);
    }

    public function testValidationFieldAliasesUseTheKnownIdentifierWithoutReadingNativeMetadata(): void
    {
        StranglerProxy::application()->setController($this->controller);
        $contract = new class extends ControllerContract
        {
            public function model(CController $controller): CActiveRecord
            {
                throw new \LogicException('The configured identifier must not query native metadata.');
            }

            public function payload(CController $controller): array
            {
                return [];
            }

            public function responseFields(string $identifier): array
            {
                TestCase::assertSame('ITEM_ID', $identifier);

                return ['name' => 'TITLE'];
            }
        };
        $config = Strangler::proxy('example')->using($contract)->uppercase('ITEM_ID')
            ->response(fields: ['name' => 'DISPLAY_NAME', 'contacts' => 'addresses', 'email' => 'MAIL'])
            ->mapJsonErrors($contract->error(...))
            ->get('get', '/api/items')->build()['config'];
        $client = new Client(['handler' => new MockHandler([
            new Response(422, [], '{"errors":{"name":["Required"],"contacts.0.email":["Invalid"]}}'),
        ])]);
        ob_start();

        try {
            self::assertTrue((new StranglerProxy($client))->handle($this->controller, $config, 'get'));
            $body = ob_get_contents();
            self::assertIsString($body);
            self::assertSame(['state' => 'error', 'error' => 'validation', 'meta' => ['DISPLAY_NAME' => ['Required' => []], 'addresses.0.MAIL' => ['Invalid' => []]]],
                json_decode($body, true));
        } finally {
            ob_end_clean();
        }
    }

    public function testRawFilterConfigurationRejectsNativeParentWithoutAControllerContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('within() requires a controller contract.');

        $this->forward([
            'feature' => 'example', 'nativeParent' => 'PARENT_ID',
            'routes'  => ['create' => ['method' => 'POST', 'path' => '/parents/{parent_id}/items']],
        ]);
    }

    /** @return CActiveRecord&MockObject */
    private function finder(): CActiveRecord
    {
        $model = $this->createMock(CActiveRecord::class);
        $schema = new CDbTableSchema;
        $schema->primaryKey = 'ITEM_ID';
        $model->method('getTableSchema')->willReturn($schema);
        $criteria = new CDbCriteria(['condition' => 'scope = 1']);
        $model->method('getDbCriteria')->willReturn($criteria);
        $model->method('setDbCriteria')->willReturnCallback(static function (CDbCriteria $copy) use ($criteria): void {
            self::assertNotSame($criteria, $copy);
            self::assertSame('scope = 1', $copy->condition);
        });

        return $model;
    }

    private function contract(CActiveRecord $model): NativeControllerContract
    {
        return new NativeControllerContract($model);
    }

    /** @param Config $config */
    private function forward(array $config): string
    {
        $path = '';
        $client = new Client(['handler' => static function (RequestInterface $request) use (&$path) {
            $path = $request->getUri()->getPath();

            return new FulfilledPromise(new Response(200, [], '{"data":{"id":"item"}}'));
        }]);
        $routes = $config['routes'] ?? [];
        $action = array_key_first($routes);
        self::assertNotNull($action);
        $_SERVER['REQUEST_METHOD'] = $routes[$action]['method'];
        ob_start();

        try {
            self::assertTrue((new StranglerProxy($client))->handle($this->controller, $config, $action));
        } finally {
            ob_end_clean();
        }

        return $path;
    }
}
