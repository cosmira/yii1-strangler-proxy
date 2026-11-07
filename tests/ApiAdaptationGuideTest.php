<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\ResponseEmitter;
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
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Yii;

final class ApiAdaptationGuideTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];

    /** @var list<string> */
    private array $headers = [];

    private int $status = 200;

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
        new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['catalog' => true, 'directory' => true]]],
        ]);
    }

    public function testPublishedResourceExampleSharesReadAndWriteNames(): void
    {
        $result = $this->forward('edit', '{"data":{"id":"42","display_name":"Desk","note":null}}',
            payload: ['ITEM_ID' => '42', 'DISPLAY_NAME' => 'Desk', 'NOTE' => null],
            builder: $this->builder('One fluent proxy'));

        self::assertSame(['state' => 'data', 'data' => ['ITEM_ID' => '42', 'DISPLAY_NAME' => 'Desk', 'NOTE' => null]], json_decode($result, true));
        self::assertSame('/items/42', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('{"display_name":"Desk","note":null}', (string) $this->history[0]['request']->getBody());
    }

    public function testPublishedDirectionalQueryExampleTranslatesOnlyTheDeclaredExceptions(): void
    {
        $this->forward('get', '{"data":[],"meta":{"total":0}}', query: [
            'filter' => ['DISPLAY_NAME' => 'Иван', 'IS_VISIBLE' => 0, 'PERSON_ID' => 'p'],
            'sort'   => ['CHANGE_DATE' => 'DESC'],
        ], builder: $this->builder('Directional query differences'));
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);

        self::assertSame(['query' => 'Иван', 'visible' => '0', 'person_id' => 'p'], $query['filter']);
        self::assertSame('-updated_at', $query['sort']);
        self::assertCount(1, $this->history);
    }

    public function testPublishedResponseGraphKeepsItsConfiguredNamingExceptions(): void
    {
        $body = $this->forward('view', '{"data":{"id":"p","updated_at":"2026-11-07","contacts":[{"id":"c","value":"user@example.test"}]}}',
            query: ['id' => 'p'], builder: $this->builder('Different semantics and response graphs'));

        self::assertSame([
            'state' => 'data', 'data' => [
                'PERIMETER_ID' => 'p', 'CHANGE_DATE' => '2026-11-07',
                'contacts'     => [['CONTACT_ID' => 'c', 'VALUE' => 'user@example.test']],
            ],
        ], json_decode($body, true));
        self::assertSame('/perimeters/p', $this->history[0]['request']->getUri()->getPath());
        self::assertCount(1, $this->history);
    }

    public function testPublishedConfigurationPreservesSingleListAndWriteContracts(): void
    {
        $item = '{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}';
        $legacy = ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7];
        $result = $this->forward('create', '{"data":'.$item.'}', 201,
            payload: ['NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]);
        self::assertSame(['success' => true, 'data' => $legacy], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->status);
        self::assertSame('POST', $this->history[0]['request']->getMethod());
        self::assertSame('{"product":{"title":"Desk","enabled":true},"category":{"id":7}}', (string) $this->history[0]['request']->getBody());

        $result = $this->forward('get', '{"data":['.$item.'],"meta":{"total":50}}', query: ['start' => 20, 'limit' => 10]);
        self::assertSame(['success' => true, 'rows' => [$legacy], 'total' => 50], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('page=3&per_page=10', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testResponseProjectionUsesOriginalRequestWithoutSharedAdapterState(): void
    {
        $body = '{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}';
        $item = ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7];
        $expanded = $this->forward('view', $body, query: ['id' => 42, 'with' => ['category']]);
        $plain = $this->forward('view', $body, query: ['id' => 42]);
        self::assertSame(['success' => true, 'data' => $item + ['CATEGORY' => ['ID' => 7]]], json_decode($expanded, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['success' => true, 'data' => $item], json_decode($plain, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $this->forward('update', '', 204, payload: ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]));
        self::assertSame(204, $this->status);
    }

    public function testValidationErrorsRetainAliasesUnknownMessagesAndTransportEnvelope(): void
    {
        $body = '{"code":"validation_failed","errors":{"title":["Required"],"product.title":["Too short"],"category.id":["Unknown category"],"product.enabled":["Invalid value"],"internal.only":["Cannot save item"]}}';
        $result = $this->forward('create', $body, 422, payload: ['NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]);
        self::assertSame(['success' => false, 'error' => ['code' => 'validation_failed', 'fields' => [
            'NAME'   => ['Required', 'Too short'], 'CATEGORY_ID' => ['Unknown category'],
            'ACTIVE' => ['Invalid value'], '_general' => ['Cannot save item'],
        ]]], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(422, $this->status);

        $result = $this->forward('view', '{"state":"error","error":"service_temporarily_unavailable"}', 503, ['id' => 42]);
        self::assertSame(['success' => false, 'error' => ['code' => 'service_temporarily_unavailable', 'fields' => []]], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(503, $this->status);
    }

    public function testPublishedExamplePreservesSafeMetadataAndLargeIdentifiers(): void
    {
        $result = $this->forward('view', '{"data":{"id":9223372036854775808,"title":"Desk","enabled":true,"category":{"id":7}}}', query: ['id' => 42], headers: [
            'X-Request-Id'     => ['request-42'], 'Retry-After' => ['3'], 'ETag' => ['obsolete'],
            'Content-Encoding' => ['gzip'], 'X-RateLimit-Remaining' => ['9'],
        ]);
        self::assertSame(['success' => true, 'data' => ['ID' => '9223372036854775808', 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertContains('X-Request-Id: request-42', $this->headers);
        self::assertContains('Retry-After: 3', $this->headers);
        self::assertContains('X-RateLimit-Remaining: 9', $this->headers);
        self::assertNotContains('ETag: obsolete', $this->headers);
        self::assertNotContains('Content-Encoding: gzip', $this->headers);
    }

    public function testMalformedAndUnexpectedResponsesBecomeOneLegacyErrorWithoutReplay(): void
    {
        foreach (['<html>unavailable</html>', '{}', '{"data":{}}'] as $body) {
            $result = $this->forward('view', $body, query: ['id' => 42]);
            self::assertSame(502, $this->status);
            self::assertSame(['success' => false, 'error' => ['code' => 'invalid_upstream_response', 'fields' => []]], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        }
        self::assertCount(3, $this->history);
    }

    public function testPublishedQueryAndPayloadBypassesNeverContactBackend(): void
    {
        $_GET = ['start' => 21, 'limit' => 10];
        $controller = new Controller('catalog');
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
        self::assertFalse((new StranglerProxy($client))->handle($controller, $this->builder()->build()['config'], 'get'));
        $_GET = [];
        $config = $this->builder()->payloadUsing(static fn (): array => ['OPTIONS' => ['MODE' => 'legacy']])->build()['config'];
        self::assertFalse((new StranglerProxy($client))->handle($controller, $config, 'update'));
    }

    /**
     * @param array<array-key, mixed>     $query
     * @param array<array-key, mixed>     $payload
     * @param array<string, list<string>> $headers
     */
    private function forward(string $action, string $body, int $status = 200, array $query = [], array $payload = [], array $headers = [], ?Strangler $builder = null): string
    {
        $_GET = $query;
        $stack = HandlerStack::create(new MockHandler([new Response($status, $headers, $body)]));
        $stack->push(Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        }));
        $config = ($builder ?? $this->builder())->payloadUsing(static fn (): array => $payload)->build()['config'];
        $emitter = new ResponseEmitter(function (string $header): void {
            $this->headers[] = $header;
        }, function (int $status): void {
            $this->status = $status;
        });
        ob_start();

        try {
            (new StranglerProxy(new Client(['handler' => $stack]), $emitter))->handle(new Controller('catalog'), $config, $action);
            self::fail('The proxy must end the request.');
        } catch (ApplicationEnded) {
            $output = ob_get_contents();
            self::assertIsString($output);

            return $output;
        } finally {
            ob_end_clean();
        }
    }

    private function builder(string $section = 'Define and connect one feature'): Strangler
    {
        $guide = file_get_contents(dirname(__DIR__).'/docs/api-adaptation.md');
        self::assertIsString($guide);
        if (preg_match('/## '.preg_quote($section, '/').'\b.*?```php\r?\n(.*?)\r?\n```/s', $guide, $matches) !== 1) {
            throw new RuntimeException('The guide must contain its runnable builder.');
        }
        $code = preg_replace('/->build\(\);\s*$/', ';', $matches[1]);
        self::assertIsString($code);
        $path = tempnam(sys_get_temp_dir(), 'strangler-guide-');
        self::assertIsString($path);

        try {
            file_put_contents($path, "<?php\n".$code);
            $builder = require $path;
            self::assertInstanceOf(Strangler::class, $builder);

            return $builder;
        } finally {
            unlink($path);
        }
    }
}
