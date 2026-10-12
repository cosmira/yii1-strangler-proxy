<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use PHPUnit\Framework\TestCase;

final class CatalogExampleTest extends TestCase
{
    public function testPublishedExamplePassesItsRealHttpContracts(): void
    {
        $output = tmpfile();
        $errors = tmpfile();
        self::assertIsResource($output);
        self::assertIsResource($errors);
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__).'/examples/catalog/verify.php',
        ], [0 => ['pipe', 'r'], 1 => $output, 2 => $errors], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $deadline = microtime(true) + 45;
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'Catalog verifier must complete within 45 seconds.');
            rewind($output);
            rewind($errors);
            $text = stream_get_contents($output);
            $errorText = stream_get_contents($errors);
            self::assertSame(0, $status['exitcode'], $text.$errorText);
            self::assertStringContainsString('All catalog contracts passed.', (string) $text);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            fclose($output);
            fclose($errors);
        }
    }
}
