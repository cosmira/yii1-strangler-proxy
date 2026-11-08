<?php

declare(strict_types=1);

use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\User;

define('YII_DEBUG', false);
require dirname(__DIR__, 2).'/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

$attemptsFile = (string) getenv('PAYLOAD_BYPASS_ATTEMPTS');
if ($_SERVER['REQUEST_URI'] === '/backend') {
    file_put_contents($attemptsFile, "attempt\n", FILE_APPEND | LOCK_EX);
    header('Content-Type: application/json');
    echo json_encode(['executor' => 'upstream', 'attempts' => count(file($attemptsFile) ?: [])], JSON_THROW_ON_ERROR);

    return;
}

final class PayloadBypassController extends CController
{
    /** @return list<array<int|string, mixed>> */
    public function filters(): array
    {
        return [Strangler::proxy('example')->post('get', '/backend')
            ->request(reader: static function (): array {
                header('X-Payload-Reads: 1');

                $payload = json_decode(StranglerProxy::application()->getRequest()->getRawBody(), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new JsonException('Expected a payload object.');
                }

                return $payload;
            })
            ->request(bypass: static function (array $payload): bool {
                header('X-Payload-Predicates: 1');

                $routing = $payload['routing'] ?? null;

                return is_array($routing) && ($routing['executor'] ?? null) === 'legacy';
            })->build()];
    }

    public function actionGet(): void
    {
        $attemptsFile = (string) getenv('PAYLOAD_BYPASS_ATTEMPTS');
        header('Content-Type: application/json');
        echo json_encode([
            'executor' => 'legacy',
            'attempts' => is_file($attemptsFile) ? count(file($attemptsFile) ?: []) : 0,
        ], JSON_THROW_ON_ERROR);
    }
}

(new CWebApplication([
    'basePath'      => dirname(__DIR__, 2),
    'runtimePath'   => dirname(__DIR__, 2),
    'controllerMap' => ['payload' => ['class' => PayloadBypassController::class]],
    'components'    => ['user' => ['class' => User::class]],
    'params'        => ['strangler' => [
        'base_uri' => getenv('PAYLOAD_BYPASS_BACKEND'),
        'features' => ['example' => true],
    ]],
]))->run();
