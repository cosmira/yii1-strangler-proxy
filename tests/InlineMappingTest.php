<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CFilterChain;
use Cosmira\Strangler\AbstractStranglerAdapter;
use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\Json;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;
use Yii;

/** @phpstan-import-type Config from Strangler */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InlineMappingTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];

    private Request $request;

    private Controller $controller;

    private WebApplication $application;

    private \CLogger $logger;

    protected function setUp(): void
    {
        define('YII_DEBUG', false);
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $this->logger = new \CLogger();
        Yii::setLogger($this->logger);
        $this->application = new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['example' => true]]],
        ]);
        $request = $this->application->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $this->request = $request;
        $this->controller = new Controller('example');
    }

    public function testAllMappersReceiveTheOriginalSnapshotAndRouteIdentifier(): void
    {
        $_GET = ['r' => 'example/get', 'start' => 20];
        $this->request->body = '{"ID":"a/b","NAME":"Desk","nullable":null}';
        $context = ['action' => 'get', 'query' => $_GET, 'payload' => ['ID' => 'a/b', 'NAME' => 'Desk', 'nullable' => null]];
        $filter = Strangler::proxy('example')->post('get', '/items/{id}')->bodyIdentifier('ID')
            ->mapQuery(static function (array $query, array $request) use ($context): array {
                self::assertSame(['start' => 20], $query);
                self::assertSame($context, $request);

                return ['page' => 2];
            })
            ->mapPayload(static function (array $payload, array $request) use ($context): stdClass {
                self::assertSame($context['payload'], $payload);
                self::assertSame($context, $request);

                return (object) ['name' => $payload['NAME']];
            })
            ->mapJsonResponse(static function (mixed $json, ResponseInterface $response, array $request) use ($context): ResponseInterface {
                self::assertSame($context, $request);

                return Json::response($response, ['ok' => true], 201);
            })->build();

        self::assertSame('{"ok":true}', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertFalse($this->controller->ran);
        self::assertSame('/items/a%2Fb', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('page=2', $this->history[0]['request']->getUri()->getQuery());
        self::assertSame('{"name":"Desk"}', (string) $this->history[0]['request']->getBody());
        self::assertSame(201, http_response_code());
    }

    public function testActionOverridesReplaceDefaultsAndRepeatedRegistrations(): void
    {
        $_GET = ['literal.key' => 'value'];
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapQuery(['literal.key' => 'global'])
            ->mapQuery(['literal.key' => 'first'], ['GET'])
            ->mapQuery(['literal.key' => 'last'], ['get'])
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'global'))
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'first'), ['GET'])
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'last'), ['get'])
            ->build();

        self::assertSame('"last"', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertSame('last=value', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testCallableArraysAreNotTreatedAsFieldMaps(): void
    {
        $mapper = new class
        {
            /**
             * @param array<array-key, mixed> $query
             *
             * @return array<array-key, mixed>
             */
            public function query(array $query): array
            {
                return ['callable' => true];
            }
        };
        $callback = [$mapper];
        $callback[] = 'query';
        $filter = Strangler::proxy('example')->get('get', '/items')->mapQuery($callback)->build();

        self::assertSame('raw', $this->executeFilter($filter, new Response(200, [], 'raw')));
        self::assertSame('callable=1', $this->history[0]['request']->getUri()->getQuery());
    }

    #[DataProvider('payloadKinds')]
    public function testEmptyPayloadObjectAndListRemainDistinct(string $kind, string $expected): void
    {
        $builder = Strangler::proxy('example')->post('get', '/items');
        if ($kind === 'fields') {
            $builder->mapPayload([]);
        } else {
            $builder->mapPayload(static fn (): array|stdClass => $kind === 'object' ? new stdClass() : []);
        }

        $this->executeFilter($builder->build(), new Response(200, [], 'raw'));

        self::assertSame($expected, (string) $this->history[0]['request']->getBody());
    }

    /** @return list<array{string, string}> */
    public static function payloadKinds(): array
    {
        return [['fields', '{}'], ['object', '{}'], ['list', '[]']];
    }

    #[DataProvider('nestedPayloadMappers')]
    public function testPayloadMappingsPreserveNestedJsonKindsAndLegacyBypassInput(bool $fieldMap): void
    {
        $this->request->body = '{"options":{},"list":[],"nested":{"id":9223372036854775808,"empty":{}},"nullable":null}';
        $assertPayload = static function (array $payload): void {
            self::assertInstanceOf(stdClass::class, $payload['options']);
            self::assertSame([], $payload['list']);
            $nested = $payload['nested'];
            self::assertInstanceOf(stdClass::class, $nested);
            self::assertSame('9223372036854775808', $nested->id);
            self::assertInstanceOf(stdClass::class, $nested->empty);
            self::assertNull($payload['nullable']);
        };
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->bypassPayloadUsing(static function (array $payload): bool {
                self::assertSame([
                    'options' => [], 'list' => [],
                    'nested'  => ['id' => '9223372036854775808', 'empty' => []], 'nullable' => null,
                ], $payload);

                return false;
            })
            ->mapQuery(static function (array $query, array $context) use ($assertPayload): array {
                self::assertIsArray($context['payload']);
                $assertPayload($context['payload']);

                return $query;
            })
            ->mapPayload($fieldMap
                ? ['options' => 'options', 'list' => 'list', 'nested' => 'nested', 'nullable' => 'nullable']
                : static function (array $payload) use ($assertPayload): array {
                    $assertPayload($payload);

                    return $payload;
                })
            ->mapJsonResponse(static function (mixed $json, ResponseInterface $response, array $context) use ($assertPayload): ResponseInterface {
                self::assertIsArray($context['payload']);
                $assertPayload($context['payload']);

                return $response;
            })->build();

        self::assertSame('{}', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertSame(
            '{"options":{},"list":[],"nested":{"id":"9223372036854775808","empty":{}},"nullable":null}',
            (string) $this->history[0]['request']->getBody(),
        );
        self::assertCount(1, $this->history);
        self::assertFalse($this->controller->ran);
    }

    /** @return list<array{bool}> */
    public static function nestedPayloadMappers(): array
    {
        return [[true], [false]];
    }

    public function testCustomPayloadReaderKeepsItsContainerSemantics(): void
    {
        $this->request->body = 'not JSON, ignored by the custom reader';
        $payload = ['options' => ['mode' => 'reader'], 'empty' => []];
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->payloadUsing(static fn (): array => $payload)
            ->mapPayload(static function (array $value, array $context) use ($payload): array {
                self::assertSame($payload, $value);
                self::assertSame($payload, $context['payload']);

                return $value;
            })->build();

        self::assertSame('raw', $this->executeFilter($filter, new Response(200, [], 'raw')));
        self::assertSame('{"options":{"mode":"reader"},"empty":[]}', (string) $this->history[0]['request']->getBody());
        self::assertCount(1, $this->history);
    }

    #[DataProvider('rawBodies')]
    public function testResponseOnlyMappingPreservesTheOriginalRequestBytes(string $body): void
    {
        $this->request->body = $body;
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => $response)->build();

        self::assertSame('{}', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertSame($body, (string) $this->history[0]['request']->getBody());
    }

    /** @return list<array{string}> */
    public static function rawBodies(): array
    {
        return [[''], ['  '], [' { "id":9223372036854775808, "float":1.0, "nested":{} } ']];
    }

    #[DataProvider('mappingStages')]
    public function testUnsupportedRequestMappingKeepsYiiBeforeAnyAttempt(string $stage): void
    {
        $builder = Strangler::proxy('example')->post('get', '/items');
        if ($stage === 'query') {
            $builder->mapQuery(static fn (): false => false)
                ->mapPayload(static function (): array {
                    self::fail('Payload mapper must not run after query bypass.');
                });
        } else {
            $builder->mapPayload(static fn (): false => false);
        }

        self::assertSame('', $this->executeFilter($builder->build(), new Response(200, [], 'raw')));
        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->history);
    }

    /** @return list<array{string}> */
    public static function mappingStages(): array
    {
        return [['query'], ['payload']];
    }

    /** @param class-string<Throwable> $failureClass */
    #[DataProvider('callbackFailures')]
    public function testCallbackFailuresDoNotBecomeBypass(string $stage, string $failureClass): void
    {
        $failure = new $failureClass('mapping failed');
        $builder = Strangler::proxy('example')->get('get', '/items');
        $mapper = static function () use ($failure): never {
            throw $failure;
        };
        if ($stage === 'query') {
            $builder->mapQuery($mapper);
        } elseif ($stage === 'payload') {
            $builder->mapPayload($mapper);
        } else {
            $builder->mapJsonResponse($mapper);
        }

        try {
            $this->executeFilter($builder->build(), new Response(200, [], '{}'));
            self::fail('The mapper exception must escape.');
        } catch (Throwable $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($this->controller->ran);
        self::assertCount($stage === 'response' ? 1 : 0, $this->history);
    }

    /** @return list<array{string, class-string<Throwable>}> */
    public static function callbackFailures(): array
    {
        return [['query', RuntimeException::class], ['payload', JsonException::class], ['response', JsonException::class]];
    }

    #[DataProvider('mappingStages')]
    public function testInvalidRequestMapperResultsFailWithoutFallback(string $stage): void
    {
        $builder = Strangler::proxy('example')->post('get', '/items');
        (new ReflectionMethod(Strangler::class, $stage === 'query' ? 'mapQuery' : 'mapPayload'))
            ->invoke($builder, static fn (): true => true);

        try {
            $this->executeFilter($builder->build(), new Response(200));
            self::fail('Invalid mapper results must fail.');
        } catch (UnexpectedValueException) {
            self::assertFalse($this->controller->ran);
            self::assertSame([], $this->history);
        }
    }

    public function testResponseJsonShapeIsPreservedAndBigIdsAreSafe(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, $json))->build();

        self::assertSame('{"data":[],"errors":{},"nullable":null,"id":"9223372036854775808"}',
            $this->executeFilter($filter, new Response(200, [], '{"data":[],"errors":{},"nullable":null,"id":9223372036854775808}')));
    }

    public function testResponseHandlerIsSelectedBeforeChangingStatus(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'success', 422))
            ->mapJsonErrors(static function (): ResponseInterface {
                self::fail('Changed success status must not reselect a handler.');
            })->build();

        self::assertSame('"success"', $this->executeFilter($filter, new Response(201, [], '{}')));
        self::assertSame(422, http_response_code());
    }

    #[DataProvider('redirectStatuses')]
    public function testRedirectsAndUnconfiguredErrorsPassThrough(int $status): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static function (): ResponseInterface {
                self::fail('Only selected JSON handlers may decode bodies.');
            })->build();

        self::assertSame('redirect', $this->executeFilter($filter, new Response($status, ['Location' => ['/elsewhere']], 'redirect')));
        self::assertSame('upstream error', $this->executeFilter($filter, new Response(422, [], 'upstream error')));
        self::assertSame(422, http_response_code());
    }

    /** @return list<array{int}> */
    public static function redirectStatuses(): array
    {
        return [[300], [302], [399]];
    }

    public function testEmptySuccessCanRestoreTheLegacyEnvelope(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static function (mixed $json, ResponseInterface $response): ResponseInterface {
                self::assertNull($json);

                return Json::response($response, ['state' => 'data'], 200);
            })->build();

        self::assertSame('{"state":"data"}', $this->executeFilter($filter, new Response(204)));
        self::assertSame(200, http_response_code());
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidUpstreamIsMappedToOneControlledError(string $body): void
    {
        $calls = 0;
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, Mapping::collection($json, ['id' => 'ID'])))
            ->mapJsonErrors(static function (mixed $json, ResponseInterface $response, array $request) use (&$calls): ResponseInterface {
                $calls++;
                self::assertSame(502, $response->getStatusCode());
                self::assertEquals((object) ['state' => 'error', 'error' => 'invalid_upstream_response'], $json);
                self::assertSame(['action' => 'get', 'query' => [], 'payload' => []], $request);

                return Json::response($response, ['legacy' => 'invalid']);
            })->build();

        self::assertSame('{"legacy":"invalid"}', $this->executeFilter($filter, new Response(200, [], $body)));
        self::assertSame(1, $calls);
        self::assertFalse($this->controller->ran);
        self::assertCount(1, $this->history);
    }

    /** @return list<array{string}> */
    public static function invalidResponses(): array
    {
        return [['not JSON'], ['{"data":{}}']];
    }

    #[DataProvider('errorStatuses')]
    public function testBrokenErrorSchemaDoesNotReenterTheErrorHandler(int $status): void
    {
        $calls = 0;
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonErrors(static function () use (&$calls): ResponseInterface {
                $calls++;

                throw new InvalidUpstreamResponse('Invalid error schema.');
            })->build();

        self::assertSame('{"state":"error","error":"invalid_upstream_response"}', $this->executeFilter($filter, new Response($status, [], '{}')));
        self::assertSame(1, $calls);
        self::assertSame(502, http_response_code());
    }

    /** @return list<array{int}> */
    public static function errorStatuses(): array
    {
        return [[400], [422]];
    }

    /** @param array<array-key, mixed>|null $payload */
    #[DataProvider('packageErrors')]
    public function testPackageErrorsUseTheSameSnapshot(string $kind, int $status, ?array $payload): void
    {
        $filter = Strangler::proxy('example')->get('get', $kind === 'route' ? '/items/{id}' : '/items')
            ->mapJsonErrors(static function (mixed $json, ResponseInterface $response, array $request) use ($status, $payload): ResponseInterface {
                self::assertSame($status, $response->getStatusCode());
                self::assertSame($payload, $request['payload']);

                return Json::response($response, ['error' => 'legacy']);
            })->build();
        if ($kind === 'body') {
            $this->request->body = '{invalid';
        } elseif ($kind === 'settings') {
            $this->application->getParams()->add('strangler', ['features' => ['example' => true]]);
        }
        $result = $kind === 'transport' ? new ConnectException('offline', new HttpRequest('GET', '/items')) : new Response(200);

        self::assertSame('{"error":"legacy"}', $this->executeFilter($filter, $result));
        self::assertFalse($this->controller->ran);
        self::assertCount($kind === 'transport' ? 1 : 0, $this->history);
    }

    /** @return list<array{string, int, array<array-key, mixed>|null}> */
    public static function packageErrors(): array
    {
        return [['body', 400, null], ['route', 400, []], ['settings', 500, []], ['transport', 503, []]];
    }

    public function testMalformedJsonWithoutAnErrorMapperReturnsTheControlledError(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static function (): ResponseInterface {
                self::fail('Malformed input must not reach the success mapper.');
            })->build();

        self::assertSame('{"state":"error","error":"invalid_upstream_response"}', $this->executeFilter($filter, new Response(200, [], 'bad JSON')));
        self::assertSame(502, http_response_code());
    }

    public function testInvalidErrorMapperForMalformedJsonRunsOnce(): void
    {
        $calls = 0;
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static function (): ResponseInterface {
                self::fail('Malformed input must not reach the success mapper.');
            })
            ->mapJsonErrors(static function () use (&$calls): ResponseInterface {
                $calls++;

                throw new InvalidUpstreamResponse('Invalid synthetic error mapping.');
            })->build();

        self::assertSame('{"state":"error","error":"invalid_upstream_response"}', $this->executeFilter($filter, new Response(200, [], 'bad JSON')));
        self::assertSame(1, $calls);
        self::assertSame(502, http_response_code());
    }

    public function testRequestSnapshotsDoNotLeakBetweenRequests(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response, array $request): ResponseInterface => Json::response($response, $request))->build();
        $_GET = ['marker' => 'first'];
        $this->request->body = '{"ID":1}';
        self::assertSame('{"action":"get","query":{"marker":"first"},"payload":{"ID":1}}', $this->executeFilter($filter, new Response(200, [], '{}')));
        $_GET = ['marker' => 'second'];
        $this->request->body = '{"ID":2}';
        self::assertSame('{"action":"get","query":{"marker":"second"},"payload":{"ID":2}}', $this->executeFilter($filter, new Response(200, [], '{}')));
    }

    public function testActionSpecificMappingsDoNotRunForOtherActions(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapQuery(static function (): array {
                self::fail('Unmatched query mapping must not run.');
            }, ['view'])
            ->mapPayload(static function (): array {
                self::fail('Unmatched payload mapping must not run.');
            }, ['view'])
            ->mapJsonResponse(static function (): ResponseInterface {
                self::fail('Unmatched response mapping must not run.');
            }, ['view'])
            ->mapJsonErrors(static function (): ResponseInterface {
                self::fail('Unmatched error mapping must not run.');
            }, ['view'])->build();

        self::assertSame('not JSON', $this->executeFilter($filter, new Response(200, [], 'not JSON')));
        self::assertSame('not JSON', $this->executeFilter($filter, new Response(422, [], 'not JSON')));
    }

    public function testPayloadAndErrorDefaultsCanBeOverriddenPerAction(): void
    {
        $this->request->body = '{"NAME":"Desk"}';
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapPayload(['NAME' => 'global'])
            ->mapPayload(['NAME' => 'first'], ['GET'])
            ->mapPayload(['NAME' => 'local'], ['get'])
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'global'))
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'first'), ['GET'])
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'local'), ['get'])->build();

        self::assertSame('"local"', $this->executeFilter($filter, new Response(422, [], '{}')));
        self::assertSame('{"local":"Desk"}', (string) $this->history[0]['request']->getBody());
    }

    public function testDisabledFeatureDoesNotExecuteAnyInlineMapper(): void
    {
        $this->application->getParams()->add('strangler', ['features' => ['example' => false]]);
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->mapQuery(static function (): array {
                self::fail('Disabled mapping must not execute.');
            })
            ->mapPayload(static function (): array {
                self::fail('Disabled mapping must not execute.');
            })
            ->mapJsonResponse(static function (): ResponseInterface {
                self::fail('Disabled mapping must not execute.');
            })
            ->mapJsonErrors(static function (): ResponseInterface {
                self::fail('Disabled mapping must not execute.');
            })->build();

        self::assertSame('', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->history);
    }

    #[DataProvider('adapterOrders')]
    public function testAdaptersAndInlineMappingsCannotBeCombined(bool $adapterFirst): void
    {
        $builder = Strangler::proxy('example');
        $adapter = new class extends AbstractStranglerAdapter {};
        if ($adapterFirst) {
            $builder->usingAdapter($adapter)->mapQuery([]);
        } else {
            $builder->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => $response)->usingAdapter($adapter);
        }

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    /** @return list<array{bool}> */
    public static function adapterOrders(): array
    {
        return [[true], [false]];
    }

    public function testMappedErrorDoesNotFallThroughWhenYiiEndReturns(): void
    {
        $this->application->shouldEnd = false;
        $this->request->body = 'null';
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapJsonErrors(static function (mixed $json, ResponseInterface $response): ResponseInterface {
                self::assertSame(400, $response->getStatusCode());

                return Json::response($response, ['code' => 'bad_payload'], 422);
            })->build();

        self::assertSame('{"code":"bad_payload"}', $this->executeFilter($filter, new Response()));
        self::assertSame(422, http_response_code());
        self::assertFalse($this->controller->ran);
        self::assertSame([], $this->history);
    }

    public function testMappedPayloadPreservesUnicodeAndFractionalZero(): void
    {
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapPayload(static fn (): array => ['name' => 'Стол', 'ratio' => 1.0])->build();

        self::assertSame('raw', $this->executeFilter($filter, new Response(200, [], 'raw')));
        self::assertSame('{"name":"Стол","ratio":1.0}', (string) $this->history[0]['request']->getBody());
    }

    #[DataProvider('unencodableValues')]
    public function testInvalidMappedJsonFailsBeforeEitherExecutor(mixed $value): void
    {
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapPayload(static fn (): array => ['value' => $value])->build();

        try {
            $this->executeFilter($filter, new Response(200));
            self::fail('Unencodable payloads must throw a JSON exception.');
        } catch (JsonException) {
            self::assertFalse($this->controller->ran);
            self::assertSame([], $this->history);
        }
    }

    /** @return list<array{mixed}> */
    public static function unencodableValues(): array
    {
        return [[NAN], ["\xB1\x31"]];
    }

    #[DataProvider('invalidFieldMaps')]
    public function testFieldMapConfigurationIsRejectedAtRegistration(string $stage, bool $duplicate): void
    {
        $builder = Strangler::proxy('example');
        $map = $duplicate ? ['first' => 'NAME', 'second' => 'NAME'] : ['first' => 42];
        $this->expectException(InvalidArgumentException::class);
        (new ReflectionMethod(Strangler::class, $stage))->invoke($builder, $map);
    }

    /** @return list<array{string, bool}> */
    public static function invalidFieldMaps(): array
    {
        return [['mapQuery', true], ['mapPayload', true], ['mapQuery', false], ['mapPayload', false]];
    }

    public function testLaterActionsPreserveEarlierMappingsAndOtherStages(): void
    {
        $_GET = ['key' => 'original'];
        $this->request->body = '{"NAME":"Desk"}';
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'get-success'), ['GET'])
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'get-error'), ['GET'])
            ->mapPayload(['NAME' => 'get_payload'], ['GET'])
            ->mapQuery(['key' => 'get_query'], ['GET'])
            ->mapQuery(['key' => 'view_query'], ['view'])
            ->mapPayload(['NAME' => 'view_payload'], ['view'])
            ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'view-success'), ['view'])
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, 'view-error'), ['view'])
            ->build();

        self::assertSame('"get-success"', $this->executeFilter($filter, new Response(200, [], '{}')));
        self::assertSame('"get-error"', $this->executeFilter($filter, new Response(422, [], '{}')));
        self::assertCount(2, $this->history);
        foreach ($this->history as $attempt) {
            self::assertSame('get_query=original', $attempt['request']->getUri()->getQuery());
            self::assertSame('{"get_payload":"Desk"}', (string) $attempt['request']->getBody());
        }
    }

    public function testUnsupportedMappingLogsTheDecisionWithoutPayloadContents(): void
    {
        $this->application->getParams()->add('strangler', ['log_requests' => true, 'features' => ['example' => true]]);
        $this->request->body = '{"secret":"payload-secret"}';
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapQuery(static fn (): false => false)->build();

        self::assertSame('', $this->executeFilter($filter, new Response(200)));
        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->history);
        $messages = implode("\n", $this->messages());
        self::assertStringContainsString('reason: mapping_unsupported', $messages);
        self::assertStringNotContainsString('payload-secret', $messages);
    }

    public function testRequestMappingFailureLogsSafeContextAndPreservesItsException(): void
    {
        $failure = new RuntimeException('secret callback failure');
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->mapPayload(static function () use ($failure): never {
                throw $failure;
            })->build();

        try {
            $this->executeFilter($filter, new Response(200));
            self::fail('The callback exception must escape.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertFalse($this->controller->ran);
        self::assertSame([], $this->history);
        $messages = implode("\n", $this->messages());
        self::assertStringContainsString('request_adaptation_failure', $messages);
        self::assertStringNotContainsString('secret callback failure', $messages);
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

    public function testEmptyActionNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('example')->mapQuery([], ['']);
    }

    /**
     * @param array{0: class-string, config: Config} $filter
     */
    private function executeFilter(array $filter, Response|ConnectException $response): string
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        }));
        $filter['client'] = new Client(['handler' => $stack]);
        ob_start();

        try {
            CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter])->run();
        } catch (ApplicationEnded) {
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }
}
