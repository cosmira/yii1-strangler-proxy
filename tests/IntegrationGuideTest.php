<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CFilterChain;
use CHttpException;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\CatalogValidationController;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IntegrationGuideTest extends TestCase
{
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
    }

    #[DataProvider('validationCases')]
    public function testPublishedValidationRunsBeforeResourceChecksAndForwarding(
        string $method,
        string $body,
        mixed $requestId,
        ?int $errorStatus,
    ): void {
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($requestId !== null) {
            $_GET['id'] = $requestId;
        }
        $application = new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => [
                'request' => ['class' => Request::class], 'user' => ['class' => User::class],
            ],
            'params' => ['strangler' => [
                'base_uri' => 'https://backend.test', 'features' => ['catalog' => true],
            ]],
        ]);
        $request = $application->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->body = $body;
        $controller = $this->controller();
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], 'mapped')]));
        $handler->push(Middleware::history($history));
        $filter = Strangler::proxy('catalog')->routeParametersUsing(static fn (array $context): array => ['id' => $context['query']['id'] ?? $context['payload']['ID'] ?? null])
            ->put('update', '/api/items/{id}')->build();
        $filter['client'] = new Client(['handler' => $handler]);
        $chain = CFilterChain::create($controller, $controller->createAction('update'), [
            'validateCatalog', 'resourceAccess', $filter,
        ]);
        ob_start();

        try {
            $chain->run();
            self::fail('Validation or forwarding must terminate the chain.');
        } catch (CHttpException $exception) {
            self::assertSame($errorStatus, $exception->statusCode);
            self::assertFalse($controller->resourceChecked);
            self::assertSame([], $history);
        } catch (ApplicationEnded) {
            self::assertNull($errorStatus);
            self::assertTrue($controller->resourceChecked);
            self::assertSame('mapped', ob_get_contents());
            self::assertIsArray($history);
            self::assertCount(1, $history);
            self::assertIsArray($history[0]);
            $upstream = $history[0]['request'];
            self::assertInstanceOf(RequestInterface::class, $upstream);
            self::assertSame('PUT', $upstream->getMethod());
            self::assertSame('/api/items/42', $upstream->getUri()->getPath());
        } finally {
            ob_end_clean();
        }
        self::assertFalse($controller->ran);
    }

    /** @return list<array{string, string, mixed, int|null}> */
    public static function validationCases(): array
    {
        return [
            ['GET', '{"ID":42}', 42, 405],
            ['POST', '{"ID":43}', 42, 400],
            ['POST', 'invalid', 42, 400],
            ['POST', '42', 42, 400],
            ['POST', '{"ID":42}', [], 400],
            ['POST', '{"ID":[]}', 42, 400],
            ['POST', '{"ID":42}', 42, null],
            ['POST', '{"ID":42}', '42', null],
            ['POST', '{"ID":42}', null, null],
        ];
    }

    private function controller(): CatalogValidationController
    {
        $guide = file_get_contents(dirname(__DIR__).'/docs/integration.md');
        self::assertIsString($guide);
        if (preg_match('/```php\r?\n(.*?)\r?\n```/s', $guide, $matches) !== 1) {
            throw new \RuntimeException('The guide must contain its runnable validation filter.');
        }
        $path = tempnam(sys_get_temp_dir(), 'strangler-validation-');
        self::assertIsString($path);

        try {
            file_put_contents($path, "<?php\nclass PublishedCatalogController extends ".
                CatalogValidationController::class." {\n".$matches[1]."\n}");
            require $path;
        } finally {
            unlink($path);
        }
        $className = 'PublishedCatalogController';
        if (! class_exists($className)) {
            throw new \RuntimeException('The published filter must declare its controller.');
        }
        $controller = new $className('catalog');
        self::assertInstanceOf(CatalogValidationController::class, $controller);

        return $controller;
    }
}
