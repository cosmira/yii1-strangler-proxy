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
error_reporting(E_ALL & ~E_DEPRECATED);
$guide = file_get_contents(dirname(__DIR__, 3).'/docs/integration.md');
if (! is_string($guide) || preg_match('/```php\r?\n(class ApiErrorController.*?)\r?\n```/s', $guide, $matches) !== 1) {
    throw new RuntimeException('The guide must contain its runnable API error controller.');
}
$path = tempnam(sys_get_temp_dir(), 'strangler-error-guide-');
if (! is_string($path)) {
    throw new RuntimeException('Could not create the guide fixture.');
}

try {
    file_put_contents($path, "<?php\n".$matches[1]);
    require $path;
} finally {
    unlink($path);
}
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
