<?php

declare(strict_types=1);

use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\CatalogValidationController;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

define('YII_DEBUG', false);
require dirname(__DIR__, 2).'/bootstrap.php';
require_once dirname(__DIR__, 3).'/vendor/yiisoft/yii/framework/yii.php';
error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 3).'/examples/catalog/common.php';
require dirname(__DIR__, 3).'/examples/catalog/controllers.php';
$application = new CWebApplication([
    'basePath'      => dirname(__DIR__, 2), 'runtimePath' => dirname(__DIR__, 2),
    'controllerMap' => ['apiError' => ['class' => 'ApiErrorController']],
    'components'    => [
        'request'      => ['class' => Request::class], 'user' => ['class' => User::class],
        'errorHandler' => ['errorAction' => 'apiError/error'],
    ],
    'params' => ['strangler' => ['base_uri' => 'https://backend.test']],
]);
$controller = new CatalogValidationController('catalog');
$history = [];
$handler = HandlerStack::create(new MockHandler([new Response(200, [], json_encode('<html>not JSON</html>', JSON_THROW_ON_ERROR))]));
$handler->push(Middleware::history($history));
$config = Strangler::proxy('catalog')->mapJsonResponse(static function (mixed $json, ResponseInterface $response): ResponseInterface {
    if ($_GET['case'] === 'throw') {
        throw new RuntimeException('Secret backend implementation detail.');
    }

    if (! is_string($json)) {
        throw new UnexpectedValueException('Expected the application payload to contain a JSON string.');
    }
    json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $response;
})->post('update', '/items')->build()['config'];

try {
    (new StranglerProxy(new Client(['handler' => $handler])))->handle($controller, $config, 'update');
} catch (Exception $exception) {
    if (! is_array($history)) {
        throw new RuntimeException('The HTTP history must remain an array.');
    }
    header('X-Backend-Attempts: '.count($history));
    header('X-Legacy-Ran: '.(int) $controller->ran);
    // Dispatch as Yii's application exception handler does; production proxy does not catch mapping errors.
    $application->handleException($exception);
}
