<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CFilterChain;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PayloadBypassTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];

    private Controller $controller;

    private WebApplication $application;

    protected function setUp(): void
    {
        if (! defined('YII_DEBUG')) {
            define('YII_DEBUG', false);
        }
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $this->application = new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
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

    #[DataProvider('gateCases')]
    public function testUnselectedFilterNeverReadsOrEvaluatesPayload(bool $enabled, string $action): void
    {
        $this->application->getParams()->add('strangler', ['features' => ['example' => $enabled]]);
        $filter = Strangler::proxy('example')->get($action, '/items')
            ->request(reader: static function (): array {
                self::fail('Unselected filter must not read the body.');
            })
            ->request(bypass: static function (): bool {
                self::fail('Unselected filter must not evaluate the predicate.');
            })
            ->build();
        $filter['client'] = $this->client();

        $this->runFilter($filter);

        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->history);
    }

    /** @return list<array{bool, string}> */
    public static function gateCases(): array
    {
        return [[false, 'get'], [true, 'view']];
    }

    public function testEarlyBypassDoesNotReadOrEvaluatePayload(): void
    {
        $config = Strangler::proxy('example')->get('get', '/items')
            ->bypassUsing(static fn (): bool => true)
            ->request(reader: static function (): array {
                self::fail('Early bypass must not read the body.');
            })
            ->request(bypass: static function (): bool {
                self::fail('Early bypass must not evaluate the predicate.');
            })
            ->build()['config'];

        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET'));
        self::assertFalse((new StranglerProxy())->handle($this->controller, $config, 'GET'));
    }

    #[DataProvider('predicateDecisions')]
    public function testPredicateUsesOriginalSnapshotAndSelectsOneExecutor(bool $bypass): void
    {
        $_GET = ['id' => '9223372036854775808', 'filter' => ['active' => null]];
        $payload = ['ID' => '9223372036854775808', 'options' => ['legacy' => $bypass], 'nullable' => null];
        $reads = 0;
        $calls = 0;
        $filter = Strangler::proxy('example')->post('get', '/items')
            ->request(reader: static function () use (&$reads, $payload): array {
                $reads++;

                return $payload;
            })
            ->request(bypass: static function (array $body, string $action, array $query) use (&$calls, $payload, $bypass): bool {
                $calls++;
                self::assertSame($payload, $body);
                self::assertSame('get', $action);
                self::assertSame(['id' => '9223372036854775808', 'filter' => ['active' => null]], $query);

                return $bypass;
            })
            ->mapPayload(static fn (array $payload): array => ['mapped' => true])->build();
        $filter['client'] = $this->client();

        $output = $this->runFilter($filter);

        self::assertSame(1, $reads);
        self::assertSame(1, $calls);
        self::assertSame($bypass, $this->controller->ran);
        self::assertCount($bypass ? 0 : 1, $this->history);
        self::assertSame($bypass ? '' : 'upstream', $output);
        if (! $bypass) {
            self::assertSame('{"mapped":true}', (string) $this->history[0]['request']->getBody());
        }
    }

    /** @return list<array{bool}> */
    public static function predicateDecisions(): array
    {
        return [[true], [false]];
    }

    public function testPayloadPredicateCanShortCircuitForAPresentNullField(): void
    {
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->request(reader: static fn (): array => ['legacy_only' => null])
            ->request(bypass: static function (array $payload): bool {
                return array_key_exists('legacy_only', $payload)
                    || throw new RuntimeException('Matching payload must short-circuit.');
            })
            ->build();
        $filter['client'] = $this->client();

        $this->runFilter($filter);

        self::assertTrue($this->controller->ran);
        self::assertSame([], $this->history);
    }

    /** @param class-string<Throwable> $exceptionClass */
    #[DataProvider('predicateFailures')]
    public function testPredicateExceptionDoesNotExecuteEitherHandler(string $exceptionClass): void
    {
        $failure = new $exceptionClass('predicate failed');
        $filter = Strangler::proxy('example')->get('get', '/items')
            ->request(bypass: static function () use ($failure): bool {
                throw $failure;
            })
            ->build();
        $filter['client'] = $this->client();

        try {
            $this->runFilter($filter);
            self::fail('Predicate exceptions must escape.');
        } catch (Throwable $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($this->controller->ran);
        self::assertSame([], $this->history);
    }

    /** @return list<array{class-string<Throwable>}> */
    public static function predicateFailures(): array
    {
        return [[RuntimeException::class], [JsonException::class]];
    }

    public function testPublicBypassUsesSuppliedSnapshotOrReadsOnceAndReplacesPredicate(): void
    {
        $reads = 0;
        $calls = 0;
        $config = Strangler::proxy('example')
            ->request(reader: static function () use (&$reads): array {
                $reads++;

                return ['source' => 'reader'];
            })
            ->request(bypass: static function (): bool {
                self::fail('The last predicate must replace the first.');
            })
            ->request(bypass: static function (array $payload, string $action, array $query) use (&$calls): bool {
                $calls++;
                self::assertSame('get', $action);
                self::assertSame([], $query);

                return $payload['source'] === 'supplied';
            })->build()['config'];

        self::assertTrue(StranglerProxy::shouldBypass($this->controller, $config, 'GET', ['source' => 'supplied']));
        self::assertSame(0, $reads);
        self::assertFalse(StranglerProxy::shouldBypass($this->controller, $config, 'GET'));
        self::assertSame(1, $reads);
        self::assertSame(2, $calls);
    }

    private function client(): Client
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'upstream')]));
        $stack->push(Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        }));

        return new Client(['handler' => $stack]);
    }

    /** @param array{0: class-string, config: array<string, mixed>, client?: Client} $filter */
    private function runFilter(array $filter): string
    {
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
