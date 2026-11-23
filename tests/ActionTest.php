<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CController;
use CFilterChain;
use Cosmira\Strangler\ControllerContract;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use Cosmira\Strangler\UpstreamTransport;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActionTest extends TestCase
{
    private Controller $controller;
    private WebApplication $app;

    /** @var list<RequestInterface> */
    private array $requests = [];

    protected function setUp(): void
    {
        if (! defined('YII_DEBUG')) {
            define('YII_DEBUG', $this->name() === 'testDebugTimingIncludesEveryAttempt');
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_GET = ['store' => 'north'];
        Yii::setApplication(null);
        $this->app = new WebApplication([
            'basePath'   => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://catalog.test', 'features' => ['catalog' => true]]],
        ]);
        $this->app->shouldEnd = false;
        $this->controller = new Controller('catalog');
    }

    public function testWriteThenReadUsesTheCreatedResourceAndReturnsTheLastResponse(): void
    {
        $attempts = [];
        $builder = Strangler::proxy('catalog')
            ->using(new class extends ControllerContract
            {
                public function payload(CController $controller): array
                {
                    return ['name' => 'Desk'];
                }
            })
            ->get('list', '/items')
            ->afterAttempt(static function (string $method, string $path) use (&$attempts): void {
                $attempts[] = [$method, $path];
            })
            ->action('Get', 'post', static function (StranglerProxy $api, array $query, array $payload): Generator {
                /** @var ResponseInterface $created */
                $created = yield $api->request('POST', '/items', body: $payload, expects: 201);
                yield $api->request('GET', $created->getHeaderLine('Location'), $query, expects: 200);
            });

        $body = $this->dispatch($builder, [
            new Response(201, ['Location' => '/items/42']),
            new Response(200, [], '{"id":42,"name":"Desk"}'),
        ]);

        self::assertSame('{"id":42,"name":"Desk"}', $body);
        self::assertSame(200, http_response_code());
        self::assertFalse($this->controller->ran);
        self::assertCount(2, $this->requests);
        self::assertSame([['POST', '/items'], ['GET', '/items/42']], $attempts);
        self::assertSame('{"name":"Desk"}', (string) $this->requests[0]->getBody());
        self::assertSame('store=north', $this->requests[1]->getUri()->getQuery());
        self::assertSame('', (string) $this->requests[1]->getBody());
    }

    #[TestWith([0, 409, 409])]
    #[TestWith([1, 422, 422])]
    #[TestWith([1, 500, 500])]
    #[TestWith([1, 200, 502])]
    #[TestWith([1, null, 503])]
    #[TestWith([0, 199, 199])]
    #[TestWith([0, 300, 300])]
    public function testStopsOnTheFirstFailure(int $step, ?int $upstream, int $expected): void
    {
        $failure = $upstream === null
            ? new ConnectException('offline', new HttpRequest('PUT', '/prices'))
            : new Response($upstream, [], '{"error":"refused"}');
        $builder = Strangler::proxy('catalog')
            ->mapJsonErrors(static fn (mixed $json, ResponseInterface $error): ResponseInterface => new Response($error->getStatusCode(), [], 'catalog error'))
            ->action('get', 'POST', static function (StranglerProxy $api): Generator {
                yield $api->request('PUT', '/items', expects: 204);
                yield $api->request('PUT', '/prices', expects: 204);
                yield $api->request('GET', '/items');
            });

        $body = $this->dispatch($builder, [...array_fill(0, $step, new Response(204)), $failure]);

        self::assertCount($step + 1, $this->requests);
        self::assertSame($expected, http_response_code());
        self::assertSame($expected >= 400 ? 'catalog error' : '{"error":"refused"}', $body);
        self::assertFalse($this->controller->ran);
    }

    #[TestWith(['method'])]
    #[TestWith(['feature'])]
    #[TestWith(['query'])]
    #[TestWith(['payload'])]
    public function testAnIneligibleActionReachesYiiBeforeAnyWrites(string $reason): void
    {
        if ($reason === 'feature') {
            $this->app->getParams()->add('strangler', ['features' => []]);
        }
        $_SERVER['REQUEST_METHOD'] = $reason === 'method' ? 'GET' : 'POST';
        $read = 0;
        $builder = Strangler::proxy('catalog')
            ->bypassUsing(static fn (): bool => $reason === 'query')
            ->request(reader: static function () use (&$read): array {
                $read++;

                return ['native' => true];
            }, nativeOn: ['native'])
            ->action('get', 'POST', static function (StranglerProxy $api): Generator {
                yield $api->request('POST', '/items');
            });

        self::assertSame('', $this->dispatch($builder, []));
        self::assertSame($reason === 'payload' ? 1 : 0, $read);
        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->requests);
    }

    #[TestWith([200])]
    #[TestWith([503])]
    public function testDebugTimingIncludesEveryAttempt(int $status): void
    {
        $now = 10.0;
        $builder = Strangler::proxy('catalog')
            ->afterAttempt(static function () use (&$now): void {
                $now += 0.125;
            })
            ->action('get', 'POST', static function (StranglerProxy $api): Generator {
                yield $api->request('POST', '/items');
                yield $api->request('GET', '/items/42');
            });
        $last = $status === 200 ? new Response(200) : new ConnectException('offline', new HttpRequest('GET', '/items/42'));
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(201), $last]))]);
        $headers = [];
        $emitter = new ResponseEmitter(static function (string $header) use (&$headers): void {
            $headers[] = $header;
        });
        $api = new StranglerProxy($client, $emitter, static function () use (&$now): float {
            return $now;
        });
        ob_start();

        try {
            self::assertTrue($api->handle($this->controller, $builder->build()['config'], 'get'));
        } finally {
            ob_end_clean();
        }
        self::assertSame($status, http_response_code());
        self::assertContains('X-Strangler-Time: 250', $headers);
        $logger = Yii::getLogger();
        self::assertInstanceOf(\CLogger::class, $logger);
        $logs = $logger->getLogs('', 'strangler');
        self::assertStringNotContainsString('/items/42', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function testANonGeneratorIsRejectedBeforeItsFirstSideEffect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('yield each upstream response');

        Strangler::proxy('catalog')->action('get', 'POST', static fn (): never => throw new \LogicException('The callback must never execute.'));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testMandatoryCheckRunsBeforeCreatingAResource(bool $denied): void
    {
        $checks = [];
        $builder = Strangler::proxy('catalog')->request(
            reader: static fn (): array => ['item' => 42],
            before: static function (array $body, string $action) use ($denied, &$checks): void {
                $checks[] = [$action, $body];
                if ($denied) {
                    throw new \DomainException('denied');
                }
            },
        )->action('get', 'POST', static function (StranglerProxy $api): Generator {
            yield $api->request('POST', '/items');
        });
        $config = $builder->build()['config'];
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'get'));
        if ($denied) {
            $this->expectException(\DomainException::class);
        }

        try {
            $this->dispatch($builder, [new Response(201)]);
        } finally {
            self::assertSame([['get', ['item' => 42]]], $checks);
            self::assertCount($denied ? 0 : 1, $this->requests);
            self::assertFalse($this->controller->ran);
        }
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    public function testValidatorSeesOnlyTheFinalResponseBeforeAnyOutput(bool $mapped, bool $reject): void
    {
        $contract = new class($reject) extends ControllerContract
        {
            /** @var list<string> */
            public array $bodies = [];

            public function __construct(private readonly bool $reject) {}

            public function payload(CController $controller): array
            {
                return [];
            }

            public function validateResponse(ResponseInterface $response): void
            {
                $this->bodies[] = $response->getBody()->getContents();
                if ($this->reject) {
                    throw new \DomainException('invalid final response');
                }
            }
        };
        $builder = Strangler::proxy('catalog')->using($contract)
            ->action('get', 'POST', static function (StranglerProxy $api): Generator {
                yield $api->request('PUT', '/items/42');
                yield $api->request('GET', '/items/42');
            });
        if ($mapped) {
            $builder->mapJsonResponse(static fn (): ResponseInterface => new Response(200, [], 'mapped'));
        }
        if ($reject) {
            $this->expectException(\DomainException::class);
        }
        $expected = $mapped ? 'mapped' : '{"id":42}';
        $raw = new NoSeekStream(Utils::streamFor('{"id":42}'));

        try {
            self::assertSame($expected, $this->dispatch($builder, [new Response(204), new Response(200, [], $raw)]));
        } finally {
            self::assertSame([$expected], $contract->bodies);
            self::assertCount(2, $this->requests);
            self::assertFalse($this->controller->ran);
        }
    }

    public function testBinaryRouteBypassesTheJsonValidator(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $contract = new class extends ControllerContract
        {
            public function identifier(): string
            {
                return 'ITEM_ID';
            }

            public function payload(CController $controller): array
            {
                return [];
            }

            public function validateResponse(ResponseInterface $response): void
            {
                json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
            }
        };
        $builder = Strangler::proxy('catalog')->using($contract)->get('get', '/items/file', binary: true);
        $file = "catalog\x00bytes";

        self::assertSame($file, $this->dispatch($builder, [new Response(200, [], $file)]));
        self::assertCount(1, $this->requests);
    }

    public function testAnInvalidYieldCannotSkipAheadToTheNextRequest(): void
    {
        $transport = new UpstreamTransport(null, new ResponseEmitter(), static fn (): float => 0.0);
        $steps = static function (): Generator {
            yield new Response(201);
            yield null;
            self::fail('An invalid step must stop the action.');
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('yield an HTTP response at each step');
        $transport->sendAction([], 'save', $steps(), null);
    }

    public function testEmptySequenceCannotDelegateToYii(): void
    {
        $builder = Strangler::proxy('catalog')->action('get', 'POST', static function (): Generator {
            yield from [];
        });
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least one upstream response');

        try {
            $this->dispatch($builder, []);
        } finally {
            self::assertFalse($this->controller->ran);
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testRoutedAndCustomActionsShareOneCaseInsensitiveNamespace(bool $manualFirst): void
    {
        $steps = static function (StranglerProxy $api): Generator {
            yield $api->request('GET', '/items');
        };
        $builder = Strangler::proxy('catalog');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate Strangler action');
        if ($manualFirst) {
            $builder->action('get', 'GET', $steps)->get('GET', '/items');
        } else {
            $builder->get('GET', '/items')->action('get', 'GET', $steps);
        }
    }

    /** @param list<Response|ConnectException> $responses */
    private function dispatch(Strangler $builder, array $responses): string
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));
        $filter = $builder->build();
        $filter['client'] = new Client(['handler' => $handler]);
        $chain = CFilterChain::create($this->controller, $this->controller->createAction('get'), [$filter]);
        ob_start();

        try {
            $chain->run();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
