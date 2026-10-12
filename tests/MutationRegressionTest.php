<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CController;
use CFilterChain;
use CLogger;
use Cosmira\Strangler\AbstractStranglerAdapter;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerAdapterInterface;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MutationRegressionTest extends TestCase
{
    private Controller $controller;

    private CLogger $logger;

    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_')) {
                unset($_SERVER[$key]);
            }
        }
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $this->logger = new CLogger();
        Yii::setLogger($this->logger);
        new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
            'language'    => 'en',
            'components'  => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
            'params' => ['strangler' => [
                'base_uri' => 'https://backend.test',
                'features' => ['example' => true],
            ]],
        ]);
        $this->controller = new Controller('example');
    }

    public function testTransportFailureLogsSafeCategoryAndTemplateBeforeCallback(): void
    {
        $_GET = ['id' => 'a/b'];
        $_SERVER['HTTP_X_REQUEST_ID'] = 'incoming-id';
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test',
            'headers'  => ['X-Request-Id' => 'server-id'],
        ]);
        $config = Strangler::proxy('example')->get('get', '/api/items/{id}')->build()['config'];
        $failure = new ConnectException('Connection refused https://backend.test/a%2Fb?token=secret', new HttpRequest('GET', '/api/items/a%2Fb'));
        $proxy = new StranglerProxy($this->client([$failure]), clock: static fn (): float => 1.0);

        $body = $this->capture(fn () => $proxy->handle($this->controller, $config, 'GeT'));

        self::assertSame('{"state":"error","error":"service_temporarily_unavailable"}', $body);
        $this->assertLog(
            'Strangler attempt | feature: example | action: get | method: GET | path: /api/items/{id}'
                .' | reason: transport_failure | selected: true | upstream_status: '
                .' | client_status:  | time_ms: 0 | request_id: server-id',
            CLogger::LEVEL_ERROR,
        );
    }

    /** @param array<string, mixed> $adapter */
    #[DataProvider('invalidAdapterLogs')]
    public function testInvalidAdapterLogsAnActionableWarning(array $adapter, string $message): void
    {
        $config = Strangler::proxy('example')->usingAdapter($adapter)->get('get', '/api/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([]));

        self::assertSame(
            '{"state":"error","error":"strangler_adapter_not_configured"}',
            $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')),
        );
        $this->assertLog($message, CLogger::LEVEL_WARNING, 2);
        $logs = $this->logger->getLogs();
        self::assertIsArray($logs[1]);
        self::assertSame([
            'Strangler request rejected | feature: example | action: get | path: /api/items'
                .' | error: strangler_adapter_not_configured | request_id: ',
            CLogger::LEVEL_WARNING, 'strangler',
        ], array_slice($logs[1], 0, 3));
    }

    /** @return list<array{array<string, mixed>, string}> */
    public static function invalidAdapterLogs(): array
    {
        return [
            [['unexpected' => true], 'Strangler adapter config must contain class'],
            [['class' => 7], 'Strangler adapter config must contain class'],
            [['class' => \stdClass::class], 'Strangler adapter must implement StranglerAdapterInterface: stdClass'],
            [['class' => 'missing.Component'], 'Strangler adapter could not be created: missing.Component'],
        ];
    }

    public function testAllAdapterCallbacksReceiveNormalizedActionAndOriginalDataInOrder(): void
    {
        $_GET = ['r' => 'example/update', 'filter' => ['name' => 'Alice']];
        $adapter = new class implements StranglerAdapterInterface
        {
            /** @var list<array<array-key, mixed>> */
            public array $calls = [];

            public function transformQuery(string $actionId, array $query): array
            {
                $this->calls[] = ['query', $actionId, $query];

                return ['page' => 2];
            }

            public function transformPayload(string $actionId, array $payload): array
            {
                $this->calls[] = ['payload', $actionId, $payload];

                return ['name' => 'mapped'];
            }

            public function transformResponse(string $actionId, int $status, string $body, array $headers): array
            {
                $this->calls[] = ['response', $actionId, $status, $body, $headers];

                return ['status' => 202, 'body' => 'changed', 'headers' => ['X-Mapped' => ['one', 'two']]];
            }
        };
        $receivedController = null;
        $config = Strangler::proxy('example')->usingAdapter($adapter)
            ->payloadUsing(static function (CController $controller) use (&$receivedController): array {
                $receivedController = $controller;

                return ['name' => 'original'];
            })->put('UpDaTe', '/api/items')->build()['config'];
        $headers = [];
        $status = null;
        $emitter = new ResponseEmitter(
            static function (string $header, bool $replace) use (&$headers): void {
                $headers[] = [$header, $replace];
            },
            static function (int $code) use (&$status): void {
                $status = $code;
            },
        );
        $proxy = new StranglerProxy($this->client([new Response(201, ['X-Upstream' => 'before'], 'original')]), $emitter);

        self::assertSame('changed', $this->capture(fn () => $proxy->handle($this->controller, $config, 'UPDATE')));
        self::assertSame($this->controller, $receivedController);
        self::assertSame([
            ['query', 'update', ['filter' => ['name' => 'Alice']]],
            ['payload', 'update', ['name' => 'original']],
            ['response', 'update', 201, 'original', ['X-Upstream' => ['before']]],
        ], $adapter->calls);
        self::assertSame(202, $status);
        self::assertSame([['X-Mapped: one', true], ['X-Mapped: two', false], ['X-Strangler: 1', true]], $headers);
    }

    private function assertLog(string $message, string $level, int $count = 1): void
    {
        $logs = $this->logger->getLogs();
        self::assertCount($count, $logs);
        $log = $logs[0];
        self::assertIsArray($log);
        self::assertSame([$message, $level, 'strangler'], array_slice($log, 0, 3));
    }

    public function testEmptyOrMissingFeatureCannotEnableAnOtherwiseMappedRoute(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', ['features' => ['' => true]]);
        $routes = ['get' => ['method' => 'GET', 'path' => '/get']];

        self::assertFalse(StranglerProxy::shouldHandle(['routes' => $routes], 'get'));
        self::assertFalse(StranglerProxy::shouldHandle(['feature' => '', 'routes' => $routes], 'get'));
    }

    public function testInvalidScalarFeatureCollectionCannotEnableANumericFeature(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', ['features' => 'x']);

        self::assertFalse(StranglerProxy::shouldHandle([
            'feature' => '0',
            'routes'  => ['get' => ['method' => 'GET', 'path' => '/get']],
        ], 'get'));
    }

    public function testRequestLoggingIsDisabledWhenTheSettingIsOmitted(): void
    {
        $config = Strangler::proxy('example')->get('get', '/items')->build()['config'];
        $proxy = new StranglerProxy($this->client([new Response(200, [], 'body')]));

        self::assertSame('body', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
        self::assertSame([], $this->logger->getLogs());
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectedRequestsLogFeaturePathErrorAndForwardedCorrelationId(
        string $path,
        string $origin,
        string $error,
    ): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'request-42';
        StranglerProxy::application()->getParams()->add('strangler', ['base_uri' => $origin]);
        $config = Strangler::proxy('example')->get('get', $path)->build()['config'];
        $proxy = new StranglerProxy($this->client([]));
        $this->capture(fn () => $proxy->handle($this->controller, $config, 'GeT'));

        $this->assertLog(
            'Strangler request rejected | feature: example | action: get | path: '.$path
                .' | error: '.$error.' | request_id: request-42',
            CLogger::LEVEL_WARNING,
        );
    }

    /** @return list<array{string, string, string}> */
    public static function rejectedRequests(): array
    {
        return [
            ['/items/{id}', 'https://backend.test', 'strangler_route_parameter_missing'],
            ['/items', '', 'strangler_not_configured'],
        ];
    }

    public function testEarlierRejectedCandidateDoesNotClaimLegacyExecution(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test', 'log_requests' => true,
            'features' => ['read' => false, 'write' => true],
        ]);
        $first = Strangler::proxy('read')->get('get', '/items')->build();
        $second = Strangler::proxy('write')->get('get', '/items')->build();
        $second['client'] = $this->client([new Response(200, [], 'upstream')]);
        $chain = CFilterChain::create(
            $this->controller, $this->controller->createAction('get'), [$first, $second],
        );

        self::assertSame('upstream', $this->capture(static fn () => $chain->run()));
        self::assertFalse($this->controller->ran);
        $logs = $this->messages();
        self::assertCount(2, $logs);
        self::assertStringContainsString('feature: read | action: get | reason: feature_disabled | selected: false', $logs[0]);
        self::assertStringContainsString('feature: write', $logs[1]);
        self::assertStringContainsString('reason: forwarded | selected: true', $logs[1]);
        self::assertStringNotContainsString('legacy', implode(' ', $logs));
    }

    public function testBypassDiagnosticsReadPayloadAndCallbackOnlyOnce(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', ['log_requests' => true]);
        $reads = 0;
        $callbacks = 0;
        $config = Strangler::proxy('example')->get('get', '/items')
            ->bypassUsing(static function () use (&$callbacks): bool {
                $callbacks++;

                return false;
            })->payloadUsing(static function () use (&$reads): array {
                $reads++;

                return ['unsupported' => true];
            })->bypassWhenPayloadHas('get', ['unsupported'])->build()['config'];
        $proxy = new StranglerProxy($this->client([]));

        self::assertFalse($proxy->handle($this->controller, $config, 'GET'));
        self::assertSame(1, $reads);
        self::assertSame(1, $callbacks);
        self::assertStringContainsString('reason: bypass_payload | selected: false', $this->messages()[0]);
        $this->logger->flush(false);
        $config['bypass'] = static fn (): bool => true;
        self::assertFalse($proxy->handle($this->controller, $config, 'get'));
        self::assertSame(1, $reads);
        self::assertStringContainsString('reason: bypass_callback | selected: false', $this->messages()[0]);
    }

    public function testUnmappedActionHasNoAttemptMetadataAndOptInLogging(): void
    {
        $config = Strangler::proxy('example')->get('view', '/items')->build()['config'];
        StranglerProxy::application()->getParams()->add('strangler', [
            'features' => ['example' => true], 'log_requests' => true,
        ]);
        self::assertFalse(StranglerProxy::shouldHandle($config, 'get'));
        self::assertFalse((new StranglerProxy($this->client([])))->handle($this->controller, $config, 'get'));
        self::assertCount(2, $this->messages());
        foreach ($this->messages() as $message) {
            self::assertStringContainsString('reason: route_unmapped | selected: false', $message);
            self::assertStringNotContainsString('status', $message);
            self::assertStringNotContainsString('time_ms', $message);
        }
        $this->logger->flush(false);
        StranglerProxy::application()->getParams()->add('strangler', []);
        self::assertFalse(StranglerProxy::shouldHandle($config, 'get'));
        self::assertSame([], $this->messages());
    }

    public function testAdaptedStatusAndAttemptTimeExcludeCallbackAndPrivateRequestData(): void
    {
        $_GET = ['id' => 'private@example.test', 'token' => 'query-secret'];
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test', 'log_requests' => true,
        ]);
        $adapter = new class extends AbstractStranglerAdapter
        {
            public function transformResponse(string $actionId, int $status, string $body, array $headers): array
            {
                return ['status' => 400, 'body' => 'legacy validation', 'headers' => []];
            }
        };
        $now = 1.0;
        $config = Strangler::proxy('example')->get('get', '/items/{id}?token=template-secret#fragment-secret')
            ->usingAdapter($adapter)->afterAttempt(static function () use (&$now): void {
                $now = 10.0;
            })->build()['config'];
        $client = new Client(['handler' => static function () use (&$now) {
            $now = 1.125;

            return new FulfilledPromise(new Response(422, [], 'private-body'));
        }]);
        $proxy = new StranglerProxy($client, clock: static function () use (&$now): float {
            return $now;
        });

        self::assertSame('legacy validation', $this->capture(fn () => $proxy->handle($this->controller, $config, 'get')));
        self::assertSame(400, http_response_code());
        $message = $this->messages()[0];
        self::assertStringContainsString('path: /items/{id} | reason: forwarded', $message);
        self::assertStringContainsString('upstream_status: 422 | client_status: 400 | time_ms: 125', $message);
        foreach (['private@example.test', 'query-secret', 'template-secret', 'fragment-secret', 'private-body'] as $secret) {
            self::assertStringNotContainsString($secret, $message);
        }
    }

    #[DataProvider('attemptFailures')]
    public function testAttemptErrorsStayObservableAndPreserveExceptions(bool $transport, bool $hook, bool $logging): void
    {
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test', 'log_requests' => $logging,
        ]);
        $failure = new \RuntimeException('secret exception URI?token=secret');
        $adapter = new class($failure) extends AbstractStranglerAdapter
        {
            public function __construct(private readonly \RuntimeException $failure) {}

            public function transformResponse(string $actionId, int $status, string $body, array $headers): array
            {
                throw $this->failure;
            }
        };
        $queue = $transport
            ? [new ConnectException('URI?token=private', new HttpRequest('GET', '/items'))]
            : [new Response(201, [], 'stored')];
        $builder = Strangler::proxy('example')->get('get', '/items/{id}');
        if ($hook) {
            $builder->afterAttempt(static function () use ($failure): void {
                throw $failure;
            });
        } else {
            $builder->usingAdapter($adapter);
        }
        $_GET = ['id' => 'private'];
        $proxy = new StranglerProxy($this->client($queue), clock: static fn (): float => 1.0);

        try {
            $proxy->handle($this->controller, $builder->build()['config'], 'get');
            self::fail('The original application exception must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertFalse($this->controller->ran);
        $logs = $this->messages();
        self::assertCount($transport ? 2 : 1, $logs);
        $last = array_pop($logs);
        self::assertIsString($last);
        self::assertStringContainsString($hook ? 'reason: callback_failure' : 'reason: adaptation_failure', $last);
        self::assertStringContainsString($transport ? 'upstream_status:  | client_status: ' : 'upstream_status: 201 | client_status: ', $last);
        $logs[] = $last;
        self::assertStringNotContainsString('secret', implode(' ', $logs));
        self::assertStringNotContainsString('private', implode(' ', $logs));
        if ($transport) {
            self::assertStringContainsString('reason: transport_failure', $logs[0]);
        }
    }

    /** @return list<array{bool, bool, bool}> */
    public static function attemptFailures(): array
    {
        return [
            [true, true, false], [true, false, false], [false, true, false], [false, false, false],
            [true, true, true], [true, false, true], [false, true, true], [false, false, true],
        ];
    }

    public function testInvalidTlsIsDiagnosedBeforeAnAttemptOrCallback(): void
    {
        StranglerProxy::application()->getParams()->add('strangler', [
            'base_uri' => 'https://backend.test', 'verify_ssl' => ['secret'],
        ]);
        $calls = 0;
        $config = Strangler::proxy('example')->get('get', '/items/{id}?token=private')
            ->afterAttempt(static function () use (&$calls): void {
                $calls++;
            })->build()['config'];
        $_GET = ['id' => 'private'];

        try {
            (new StranglerProxy($this->client([])))->handle($this->controller, $config, 'get');
            self::fail('Invalid TLS configuration must propagate.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('verify_ssl must be a boolean or a CA bundle path.', $exception->getMessage());
        }
        self::assertSame(0, $calls);
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('error: transport_configuration_failure', $messages[0]);
        self::assertStringNotContainsString('private', $messages[0]);
        self::assertStringNotContainsString('secret', $messages[0]);
        self::assertStringNotContainsString('time_ms', $messages[0]);
    }

    #[DataProvider('mappingStages')]
    public function testRequestMappingErrorsAreDiagnosedWithoutAnAttempt(string $stage): void
    {
        $failure = new \RuntimeException('private input');
        $adapter = new class($failure, $stage) extends AbstractStranglerAdapter
        {
            public function __construct(private readonly \RuntimeException $failure, private readonly string $stage) {}

            public function transformQuery(string $actionId, array $query): array
            {
                if ($this->stage === 'query') {
                    throw $this->failure;
                }

                return $query;
            }

            public function transformPayload(string $actionId, array $payload): array
            {
                throw $this->failure;
            }
        };
        $config = Strangler::proxy('example')->get('get', '/items/{id}?token=private')
            ->usingAdapter($adapter)->build()['config'];
        $_GET = ['id' => 'private'];

        try {
            (new StranglerProxy($this->client([])))->handle($this->controller, $config, 'get');
            self::fail('The request mapping exception must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('path: /items/{id} | error: request_adaptation_failure', $messages[0]);
        self::assertStringNotContainsString('private', $messages[0]);
        self::assertStringNotContainsString('selected: true', $messages[0]);
    }

    /** @return list<array{string}> */
    public static function mappingStages(): array
    {
        return [['query'], ['payload']];
    }

    /** @return list<string> */
    private function messages(): array
    {
        $messages = [];
        foreach ($this->logger->getLogs() as $log) {
            self::assertIsArray($log);
            self::assertIsString($log[0]);
            $messages[] = $log[0];
        }

        return $messages;
    }

    /** @param list<Response|ConnectException> $responses */
    private function client(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    /** @param callable(): mixed $operation */
    private function capture(callable $operation): string
    {
        ob_start();

        try {
            $operation();
            self::fail('The proxy must end the Yii request.');
        } catch (ApplicationEnded) {
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
