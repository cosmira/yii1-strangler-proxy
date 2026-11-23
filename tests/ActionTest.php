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

    public function testChosenRoutePreparesOnceAndMapsErrorsAgainstTheOriginalForm(): void
    {
        $form = ['category_ids' => ['old', 'new'], 'row' => 7];
        $reads = $choices = 0;
        $checks = [];
        $builder = Strangler::proxy('catalog')
            ->request(reader: static function () use (&$reads, $form): array {
                $reads++;

                return $form;
            })
            ->choose('Get', 'POST', static function (Strangler $routes, array $payload, array $query) use (&$choices, $form): Strangler {
                $choices++;
                self::assertSame($form, $payload);
                self::assertSame(['store' => 'north'], $query);
                $destination = $payload['category_ids'][1];

                return $routes
                    ->patch('get', '/items/move', body: static fn (): array => ['category' => $destination])
                    ->mapJsonErrors(static function (mixed $json, ResponseInterface $response, array $context) use ($form, $destination): ResponseInterface {
                        self::assertSame('get', $context['action']);
                        self::assertSame($form, $context['payload']);

                        return new Response(400, [], 'Cannot move row '.$form['row'].' to '.$destination);
                    }, ['get']);
            })
            ->request(actions: ['get'], before: static function (array $payload, string $action) use (&$checks): void {
                $checks[] = [$action, $payload];
            });

        self::assertSame('Cannot move row 7 to new', $this->dispatch($builder, [new Response(422, [], '{"errors":{}}')]));
        self::assertSame(400, http_response_code());
        self::assertSame(1, $reads);
        self::assertSame(1, $choices);
        self::assertSame([['get', $form]], $checks);
        self::assertCount(1, $this->requests);
        self::assertSame('PATCH', $this->requests[0]->getMethod());
        self::assertSame('/items/move', $this->requests[0]->getUri()->getPath());
        self::assertSame('{"category":"new"}', (string) $this->requests[0]->getBody());
        self::assertFalse($this->controller->ran);
    }

    public function testChosenSequenceStopsOnAnUpstreamErrorWithoutBindingARecord(): void
    {
        $contract = new class extends ControllerContract
        {
            public function payload(CController $controller): array
            {
                return ['source' => 'old'];
            }
        };
        $builder = Strangler::proxy('catalog')->using($contract)
            ->choose('get', 'POST', static function (Strangler $routes, array $form): Strangler {
                self::assertSame(['source' => 'old'], $form);

                return $routes->action('get', 'POST', static function (StranglerProxy $api): Generator {
                    yield $api->request('DELETE', '/categories/old/items/42', expects: 204);
                    yield $api->request('GET', '/items/42');
                });
            })->mapJsonErrors(static fn (): ResponseInterface => new Response(409, [], 'still linked'));

        self::assertSame('still linked', $this->dispatch($builder, [new Response(422, [], '{}')]));
        self::assertCount(1, $this->requests);
        self::assertSame('DELETE', $this->requests[0]->getMethod());
        self::assertSame(409, http_response_code());
        self::assertFalse($this->controller->ran);
    }

    #[TestWith(['feature', 0, 0])]
    #[TestWith(['method', 0, 0])]
    #[TestWith(['query', 0, 0])]
    #[TestWith(['unsupported', 1, 1])]
    #[TestWith(['selected-method', 1, 1])]
    public function testIneligibleChoiceContinuesInYiiBeforeHttp(string $reason, int $reads, int $choices): void
    {
        if ($reason === 'feature') {
            $this->app->getParams()->add('strangler', ['features' => []]);
        }
        $_SERVER['REQUEST_METHOD'] = $reason === 'method' ? 'GET' : 'POST';
        $read = $chosen = 0;
        $builder = Strangler::proxy('catalog')
            ->bypassUsing(static fn (): bool => $reason === 'query')
            ->request(reader: static function () use (&$read): array {
                $read++;

                return [];
            })->choose('get', 'POST', static function (Strangler $routes) use (&$chosen, $reason): ?Strangler {
                $chosen++;

                return $reason === 'unsupported' ? null : $routes->patch('get', '/items', incoming: 'DELETE');
            });

        self::assertSame('', $this->dispatch($builder, []));
        self::assertSame($reads, $read);
        self::assertSame($choices, $chosen);
        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->requests);
    }

    public function testMalformedFormNeverEntersTheSelector(): void
    {
        $request = $this->app->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->body = '{';
        $builder = Strangler::proxy('catalog')->choose('get', 'POST', static fn (): never => self::fail('Malformed JSON reached the selector.'));

        self::assertStringContainsString('strangler_invalid_payload', $this->dispatch($builder, []));
        self::assertSame(400, http_response_code());
        self::assertFalse($this->controller->ran);
        self::assertSame([], $this->requests);
    }

    public function testChoiceBypassUsesTheSuppliedFormAndTheSelectedRouteGuards(): void
    {
        $reads = 0;
        $config = Strangler::proxy('catalog')->request(reader: static function () use (&$reads): array {
            $reads++;

            return ['local' => true];
        })->choose('get', 'POST', static fn (Strangler $routes, array $form): ?Strangler => $form === []
            ? null
            : $routes->request(nativeOn: ['local'])->post('get', '/items'))->build()['config'];

        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET', []));
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET', ['local' => true]));
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'GET', ['move' => true]));
        self::assertSame(0, $reads);
        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET'));
        self::assertSame(1, $reads);
    }

    #[TestWith(['outer'])]
    #[TestWith(['inner'])]
    #[TestWith([null])]
    public function testOuterAndChosenBypassesRunOnceInOrder(?string $blocked): void
    {
        $calls = [];
        $builder = Strangler::proxy('catalog')
            ->bypassUsing(static function () use (&$calls): bool {
                $calls[] = 'outer query';

                return false;
            })
            ->request(bypass: static function () use (&$calls, $blocked): bool {
                $calls[] = 'outer form';

                return $blocked === 'outer';
            })
            ->choose('get', 'POST', static function (Strangler $routes) use (&$calls, $blocked): Strangler {
                $calls[] = 'choose';

                return $routes->bypassUsing(static function () use (&$calls): bool {
                    $calls[] = 'inner query';

                    return false;
                })->request(bypass: static function () use (&$calls, $blocked): bool {
                    $calls[] = 'inner form';

                    return $blocked === 'inner';
                })->post('get', '/items');
            });

        $this->dispatch($builder, $blocked === null ? [new Response(204)] : []);
        self::assertSame($blocked === 'outer'
            ? ['outer query', 'outer form']
            : ['outer query', 'outer form', 'choose', 'inner query', 'inner form'], $calls);
        self::assertSame($blocked !== null, $this->controller->ran);
        self::assertCount($blocked === null ? 1 : 0, $this->requests);
    }

    public function testChoiceCanNormalizeObjectsWithoutChangingTheNativeFormOrQuery(): void
    {
        $form = ['item' => (object) ['name' => ' Desk ']];
        $_GET = ['filter' => (object) ['store' => 'NORTH']];
        $original = json_encode([$form, $_GET], JSON_THROW_ON_ERROR);
        $builder = Strangler::proxy('catalog')->request(reader: static fn (): array => $form)
            ->choose('get', 'POST', static function (Strangler $routes, array $form, array $query) use ($original): Strangler {
                self::assertInstanceOf(\stdClass::class, $item = $form['item']);
                self::assertInstanceOf(\stdClass::class, $filter = $query['filter']);
                $item->name = 'Desk';
                $filter->store = 'north';

                return $routes
                    ->request(bypass: static fn (array $form, string $action, array $query): bool => json_encode([$form, $query], JSON_THROW_ON_ERROR) !== $original)
                    ->patch('get', '/items', body: static fn (): array => ['name' => $item->name, 'store' => $filter->store])
                    ->mapJsonErrors(static fn (mixed $json, ResponseInterface $error, array $context): ResponseInterface => new Response(422, [], json_encode([$context['payload'], $context['query']], JSON_THROW_ON_ERROR)));
            });

        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $builder->build()['config'], 'get', $form));
        self::assertSame($original, json_encode([$form, $_GET], JSON_THROW_ON_ERROR));
        self::assertSame($original, $this->dispatch($builder, [new Response(422, [], '{}')]));
        self::assertSame($original, json_encode([$form, $_GET], JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->requests);
        self::assertSame('{"name":"Desk","store":"north"}', (string) $this->requests[0]->getBody());
        self::assertFalse($this->controller->ran);
    }

    public function testBuiltChoiceIsIsolatedAcrossRequestsAndLaterBuilderChanges(): void
    {
        $builder = Strangler::proxy('catalog')->choose('get', 'POST', static function (Strangler $routes, array $form): Strangler {
            if ($form['move'] === true) {
                return $routes->patch('get', '/items/move')->mapJsonResponse(
                    static fn (): ResponseInterface => new Response(200, [], 'moved'),
                );
            }

            return $routes->post('get', '/items');
        });
        $config = $builder->build()['config'];
        $builder->request(before: static fn (): never => self::fail('Later changes leaked into an earlier build.'));
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{}'), new Response(201, [], '{"created":true}')]));
        $handler->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));
        $proxy = new StranglerProxy(new Client(['handler' => $handler]));
        $input = $this->app->getRequest();
        self::assertInstanceOf(Request::class, $input);
        $create = ' { "move": false, "metadata": {}, "id": 9223372036854775808 } ';
        foreach (['{"move":true}' => 'moved', $create => '{"created":true}'] as $body => $expected) {
            $input->body = $body;
            ob_start();

            try {
                self::assertTrue($proxy->handle($this->controller, $config, 'get'));
                self::assertSame($expected, ob_get_contents());
            } finally {
                ob_end_clean();
            }
        }
        self::assertCount(2, $this->requests);
        self::assertSame('/items/move', $this->requests[0]->getUri()->getPath());
        self::assertSame('/items', $this->requests[1]->getUri()->getPath());
        self::assertSame($create, (string) $this->requests[1]->getBody());
    }

    public function testSecondChoiceUsesItsSharedContractAlongsideOrdinaryAndCustomActions(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(reader: static fn (): array => ['TITLE' => 'Desk'])
            ->get('view', '/items/{id}')
            ->action('archive', 'POST', static function (StranglerProxy $api): Generator {
                yield $api->request('POST', '/archive');
            })
            ->choose('export', 'POST', static fn (): never => self::fail('The selector belongs to another action.'))
            ->choose('get', 'POST', static function (Strangler $routes, array $form): Strangler {
                self::assertSame(['TITLE' => 'Desk'], $form);

                return $routes->post('get', '/items/search', body: ['title' => 'TITLE']);
            });

        $result = $this->dispatch($builder, [new Response(200, [], '{"data":[{"id":"item","name":"Desk"}],"meta":{"total":1}}')]);
        self::assertSame([
            'state' => 'data', 'data' => [['ITEM_ID' => 'item', 'NAME' => 'Desk']], 'meta' => ['totalCount' => 1],
        ], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->requests);
        self::assertSame('/items/search', $this->requests[0]->getUri()->getPath());
        self::assertSame('{"title":"Desk"}', (string) $this->requests[0]->getBody());
        self::assertFalse($this->controller->ran);
    }

    #[TestWith(['unconfigured'])]
    #[TestWith(['other-action'])]
    #[TestWith(['two-actions'])]
    #[TestWith(['other-builder'])]
    #[TestWith(['nested-choice'])]
    public function testInvalidChoiceCannotDispatchAnotherActionOrEscapeItsGuards(string $kind): void
    {
        $builder = Strangler::proxy('catalog')->choose('get', 'POST', static function (Strangler $routes) use ($kind): Strangler {
            if ($kind === 'unconfigured') {
                return $routes;
            }
            if ($kind === 'other-action') {
                return $routes->put('other', '/other');
            }
            $routes->put('get', '/items');

            return match ($kind) {
                'two-actions'   => $routes->put('other', '/other'),
                'nested-choice' => $routes->choose('other', 'POST', static fn (): null => null),
                default         => Strangler::proxy('other-feature')->put('get', '/other'),
            };
        });
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->dispatch($builder, []);
        } finally {
            self::assertSame([], $this->requests);
            self::assertFalse($this->controller->ran);
        }
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

    #[TestWith(['action', 'route'])]
    #[TestWith(['route', 'action'])]
    #[TestWith(['choice', 'route'])]
    #[TestWith(['route', 'choice'])]
    #[TestWith(['choice', 'action'])]
    #[TestWith(['action', 'choice'])]
    #[TestWith(['choice', 'choice'])]
    public function testRoutesActionsAndChoicesShareOneCaseInsensitiveNamespace(string $first, string $second): void
    {
        $steps = static function (StranglerProxy $api): Generator {
            yield $api->request('GET', '/items');
        };
        $builder = Strangler::proxy('catalog');
        $register = static fn (string $kind, string $name): Strangler => match ($kind) {
            'action' => $builder->action($name, 'GET', $steps),
            'choice' => $builder->choose($name, 'GET', static fn (): null => null),
            default  => $builder->get($name, '/items'),
        };
        $register($first, 'get');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate Strangler action');
        $register($second, 'GET');
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
