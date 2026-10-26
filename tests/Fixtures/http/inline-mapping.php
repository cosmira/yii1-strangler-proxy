<?php

declare(strict_types=1);

use Cosmira\Strangler\Json;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\Tests\Fixtures\User;
use Psr\Http\Message\ResponseInterface;

define('YII_DEBUG', false);
require dirname(__DIR__, 2).'/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

$attemptsFile = (string) getenv('INLINE_ATTEMPTS');
$mode = $_GET['mode'] ?? 'raw';
$requestTarget = $_SERVER['REQUEST_URI'];
if (is_string($requestTarget) && str_starts_with($requestTarget, '/backend')) {
    file_put_contents($attemptsFile, "attempt\n", FILE_APPEND | LOCK_EX);
    if ($mode === 'malformed') {
        echo '<html>invalid upstream</html>';
    } elseif ($mode === 'empty') {
        http_response_code(204);
    } elseif ($mode === 'redirect') {
        header('Location: /moved', true, 302);
        echo 'redirect body';
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'body'     => file_get_contents('php://input'),
            'attempts' => count(file($attemptsFile) ?: []),
            'data'     => 'invalid collection',
        ], JSON_THROW_ON_ERROR);
    }

    return;
}

final class InlineHttpController extends CController
{
    /** @return list<array<int|string, mixed>> */
    public function filters(): array
    {
        $builder = Strangler::proxy('inline')
            ->mapQuery(static fn (array $query): array|false => ($query['mode'] ?? null) === 'bypass' ? false : $query)
            ->mapJsonResponse(static function (mixed $json, ResponseInterface $response, array $request): ResponseInterface {
                header('X-Success-Handler: 1');
                if (($request['query']['mode'] ?? null) === 'empty') {
                    return Json::response($response, ['executor' => 'upstream', 'empty' => true], 200);
                }
                if (($request['query']['mode'] ?? null) === 'bad-shape') {
                    return Json::response($response, Mapping::collection($json, ['id' => 'ID']));
                }
                if (! $json instanceof stdClass) {
                    throw new RuntimeException('Expected backend object.');
                }

                return Json::response($response, [
                    'executor' => 'upstream',
                    'body'     => $json->body,
                    'attempts' => $json->attempts,
                    'context'  => $request,
                ]);
            })
            ->mapJsonErrors(static function (mixed $json, ResponseInterface $response, array $request): ResponseInterface {
                header('X-Error-Handler: 1');

                return Json::response($response, [
                    'executor' => 'error', 'payload' => $request['payload'],
                    'status'   => $response->getStatusCode(),
                ]);
            })
            ->post('get', '/backend');
        if (($_GET['mode'] ?? null) === 'remap') {
            $builder->mapPayload(['NAME' => 'name']);
        }

        return [$builder->build()];
    }

    public function actionGet(): void
    {
        $attemptsFile = (string) getenv('INLINE_ATTEMPTS');
        header('Content-Type: application/json');
        echo json_encode(['executor' => 'legacy', 'attempts' => count(file($attemptsFile) ?: [])], JSON_THROW_ON_ERROR);
    }
}

(new CWebApplication([
    'basePath'      => dirname(__DIR__, 2),
    'runtimePath'   => dirname(__DIR__, 2),
    'controllerMap' => ['inline' => ['class' => InlineHttpController::class]],
    'components'    => ['user' => ['class' => User::class]],
    'params'        => ['strangler' => [
        'base_uri' => getenv('INLINE_BACKEND'),
        'features' => ['inline' => true],
    ]],
]))->run();
