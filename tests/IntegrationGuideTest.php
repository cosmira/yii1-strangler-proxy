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
        $filter = Strangler::proxy('catalog')->request(parameters: static fn (array $context): array => ['id' => $context['query']['id'] ?? $context['payload']['ID'] ?? null])
            ->put('update', '/api/items/{id}')->build();
        $filter['client'] = new Client(['handler' => $handler]);
        $chain = CFilterChain::create($controller, $controller->createAction('update'), [
            'validateInput', 'resourceAccess', $filter,
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
        $fields = ',"NAME":"Desk","ACTIVE":true,"CATEGORY_ID":7';

        return [
            ['GET', '{"ID":42'.$fields.'}', 42, 405],
            ['POST', '{"ID":43'.$fields.'}', 42, 400],
            ['POST', 'invalid', 42, 400],
            ['POST', '42', 42, 400],
            ['POST', '{"ID":42'.$fields.'}', [], 400],
            ['POST', '{"ID":[]'.$fields.'}', 42, 400],
            ['POST', '{"ID":42'.$fields.'}', 42, null],
            ['POST', '{"ID":42'.$fields.'}', '42', null],
            ['POST', '{"ID":42'.$fields.'}', null, null],
        ];
    }

    private function controller(): CatalogValidationController
    {
        require_once dirname(__DIR__).'/examples/catalog/controllers.php';
        $method = new \ReflectionMethod('CatalogController', 'filterValidateInput');
        $file = $method->getFileName();
        self::assertIsString($file);
        $source = file($file);
        self::assertIsArray($source);
        $filter = implode('', array_slice(
            $source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $path = tempnam(sys_get_temp_dir(), 'strangler-validation-');
        self::assertIsString($path);

        try {
            file_put_contents($path, "<?php\nclass PublishedCatalogController extends ".
                CatalogValidationController::class." {\n".$filter."\n}");
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
