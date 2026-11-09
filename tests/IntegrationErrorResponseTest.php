<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class IntegrationErrorResponseTest extends TestCase
{
    public function testPublishedYiiErrorActionReturnsJsonWithoutReplayingWrites(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $pipes = [];
        $server = proc_open([
            PHP_BINARY, '-S', $address, __DIR__.'/Fixtures/http/api-error.php',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($server);

        try {
            $connected = false;
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $connected = true;
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($connected, 'PHP HTTP server must become ready.');
            $client = new Client(['base_uri' => 'http://'.$address, 'timeout' => 5, 'http_errors' => false]);
            foreach (['malformed', 'throw'] as $case) {
                $response = $client->post('/?case='.$case);
                self::assertSame(500, $response->getStatusCode(), (string) $response->getBody());
                self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
                self::assertSame('{"success":false,"error":{"code":"internal_error","fields":{}}}', (string) $response->getBody());
                self::assertSame('1', $response->getHeaderLine('X-Backend-Attempts'));
                self::assertSame('0', $response->getHeaderLine('X-Legacy-Ran'));
            }
        } finally {
            proc_terminate($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }
    }
}
