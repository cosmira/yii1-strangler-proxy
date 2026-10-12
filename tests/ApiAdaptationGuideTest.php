<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use UnexpectedValueException;
use Yii;

final class ApiAdaptationGuideTest extends TestCase
{
    public function testPublishedExamplePreservesTheLegacyCatalogContract(): void
    {
        $adapter = $this->adapter();
        self::assertSame(['page' => 3, 'per_page' => 10], $adapter->transformQuery('get', ['start' => 20, 'limit' => 10]));
        self::assertSame([], $adapter->transformQuery('create', []));
        self::assertSame([], $adapter->transformPayload('get', []));
        self::assertSame([
            'product'  => ['title' => 'Desk', 'enabled' => true],
            'category' => ['id' => 7],
        ], $adapter->transformPayload('update', ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7]));
        $item = ['id' => 42, 'title' => 'Desk', 'enabled' => true, 'category' => ['id' => 7]];
        $legacyItem = ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7];
        $response = $adapter->transformResponse('create', 201, json_encode(['data' => $item], JSON_THROW_ON_ERROR), []);
        self::assertSame(201, $response['status']);
        self::assertSame(['success' => true, 'data' => $legacyItem], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['Content-Type' => ['application/json; charset=utf-8']], $response['headers']);
        $response = $adapter->transformResponse('get', 200,
            json_encode(['data' => [$item], 'meta' => ['total' => 50]], JSON_THROW_ON_ERROR), []);
        self::assertSame(['success' => true, 'total' => 50, 'rows' => [$legacyItem]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 204, 'body' => '', 'headers' => []], $adapter->transformResponse('update', 204, '', []));
    }

    public function testPublishedExampleMapsValidationAndTransportErrors(): void
    {
        $adapter = $this->adapter();
        $response = $adapter->transformResponse('create', 422, '{"code":"validation_failed","errors":{"title":["Required"]}}', []);
        self::assertSame(422, $response['status']);
        self::assertSame(['success' => false, 'error' => ['code' => 'validation_failed', 'fields' => ['NAME' => ['Required']]]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
        $response = $adapter->transformResponse('create', 503, '{"state":"error","error":"service_temporarily_unavailable"}', []);
        self::assertSame(503, $response['status']);
        self::assertSame(['success' => false, 'error' => ['code' => 'service_temporarily_unavailable', 'fields' => []]],
            json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPublishedValidationErrorsUseLegacyFieldsAndRetainUnknownMessages(): void
    {
        $response = $this->adapter()->transformResponse('create', 422, json_encode([
            'code'   => 'validation_failed',
            'errors' => [
                'title'           => ['Required'],
                'product.title'   => ['Too short'],
                'category.id'     => ['Unknown category'],
                'product.enabled' => ['Invalid value'],
                'internal.only'   => ['Cannot save item'],
            ],
        ], JSON_THROW_ON_ERROR), []);
        self::assertSame(422, $response['status']);
        self::assertSame([
            'success' => false,
            'error'   => ['code' => 'validation_failed', 'fields' => [
                'NAME'        => ['Required', 'Too short'],
                'CATEGORY_ID' => ['Unknown category'],
                'ACTIVE'      => ['Invalid value'],
                '_general'    => ['Cannot save item'],
            ]],
        ], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPublishedExamplePreservesUsefulHeadersAndLargeIdentifiers(): void
    {
        $adapter = $this->adapter();
        $response = $adapter->transformResponse('update', 200,
            '{"data":{"id":9223372036854775808,"title":"Desk","enabled":true,"category":{"id":7}}}',
            ['X-Request-Id'        => ['request-42'], 'Retry-After' => ['3'], 'ETag' => ['obsolete'],
                'Content-Encoding' => ['gzip'], 'X-RateLimit-Remaining' => ['9']]);
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data']);
        self::assertSame('9223372036854775808', $decoded['data']['ID']);
        self::assertSame([
            'Content-Type' => ['application/json; charset=utf-8'], 'X-Request-Id' => ['request-42'],
            'Retry-After'  => ['3'], 'X-RateLimit-Remaining' => ['9'],
        ], $response['headers']);
        $payload = $adapter->transformPayload('update',
            ['NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => '9223372036854775808']);
        self::assertIsArray($payload['category']);
        self::assertSame('9223372036854775808', $payload['category']['id']);
    }

    public function testPublishedExampleRejectsUnexpectedBackendResponses(): void
    {
        $adapter = $this->adapter();
        foreach (['<html>unavailable</html>', 'null', '{}', '{"data":{}}'] as $body) {
            try {
                $adapter->transformResponse('update', 200, $body, []);
                self::fail('Unexpected backend responses must reach the host error handler.');
            } catch (JsonException|UnexpectedValueException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testPublishedPaginationBypassNeverRoundsLegacyOffsets(): void
    {
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['features' => ['catalog' => true]]],
        ]);
        $guide = file_get_contents(dirname(__DIR__).'/docs/api-adaptation.md');
        self::assertIsString($guide);
        if (preg_match('/```php\r?\n(Strangler::proxy.*?)\r?\n```/s', $guide, $matches) !== 1) {
            throw new RuntimeException('The guide must contain its runnable route builder.');
        }
        $builderCode = preg_replace('/->build\(\)\s*$/', '', $matches[1]);
        self::assertIsString($builderCode);
        $this->adapter();
        $path = tempnam(sys_get_temp_dir(), 'strangler-guide-routes-');
        self::assertIsString($path);

        try {
            file_put_contents($path, "<?php\nuse Cosmira\\Strangler\\Strangler;\nreturn ".$builderCode.";\n");
            $builder = require $path;
        } finally {
            unlink($path);
        }
        self::assertInstanceOf(Strangler::class, $builder);
        $filter = $builder->build();
        $config = $filter['config'];
        $bypass = $config['bypass'] ?? null;
        self::assertNotNull($bypass);
        $controller = new Controller('catalog');
        self::assertTrue($bypass($controller, 'get', ['start' => 21, 'limit' => 10]));
        self::assertFalse($bypass($controller, 'get', ['start' => 20, 'limit' => 10]));
        $_GET = ['start' => 21, 'limit' => 10];
        $proxy = new StranglerProxy(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        // Any backend call would fail the empty handler; bypass must keep the legacy executor.
        self::assertFalse($proxy->handle($controller, $config, 'get'));
    }

    public function testGuideUpdateActuallyUsesTheNewMethodPathAndLegacyResponse(): void
    {
        $_GET = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['catalog' => true]]],
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [],
            '{"data":{"id":42,"title":"Desk","enabled":true,"category":{"id":7}}}')]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $config = Strangler::proxy('catalog')->usingAdapter($this->adapter())->bodyIdentifier('ID')
            ->payloadUsing(static fn (): array => ['ID' => 42, 'NAME' => 'Desk', 'ACTIVE' => 1, 'CATEGORY_ID' => 7])
            ->put('update', '/api/items/{id}')->build()['config'];
        $proxy = new StranglerProxy($client, new ResponseEmitter(static function (): void {}));
        ob_start();

        try {
            $proxy->handle(new Controller('catalog'), $config, 'update');
            self::fail('Expected Yii request termination.');
        } catch (ApplicationEnded) {
            self::assertSame('{"success":true,"data":{"ID":42,"NAME":"Desk","ACTIVE":1,"CATEGORY_ID":7}}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertIsArray($history[0]);
        $request = $history[0]['request'];
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/api/items/42', $request->getUri()->getPath());
        self::assertSame('{"product":{"title":"Desk","enabled":true},"category":{"id":7}}', (string) $request->getBody());
    }

    private function adapter(): StranglerAdapterInterface
    {
        if (! class_exists('CatalogApiAdapter', false)) {
            $guide = file_get_contents(dirname(__DIR__).'/docs/api-adaptation.md');
            self::assertIsString($guide);
            if (preg_match('/```php\r?\n(.*?)\r?\n```/s', $guide, $matches) !== 1) {
                throw new RuntimeException('The guide must contain its runnable adapter example.');
            }
            $path = tempnam(sys_get_temp_dir(), 'strangler-guide-');
            self::assertIsString($path);

            try {
                file_put_contents($path, "<?php\n".$matches[1]);
                require $path;
            } finally {
                unlink($path);
            }
        }
        $className = 'CatalogApiAdapter';
        if (! class_exists($className)) {
            throw new RuntimeException('The published adapter example must declare its class.');
        }
        $adapter = new $className();
        self::assertInstanceOf(StranglerAdapterInterface::class, $adapter);

        return $adapter;
    }
}
