<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use PHPUnit\Framework\TestCase;

final class CatalogConfigurationTest extends TestCase
{
    public function testDisabledConfigurationIsValidatedWithoutStartingApplication(): void
    {
        $directory = sys_get_temp_dir().'/catalog-preflight-'.bin2hex(random_bytes(8));
        [$status, $report] = $this->check([
            'STRANGLER_TOKEN'       => 'preflight-secret',
            'CATALOG_RUNTIME_DIR'   => $directory,
            'CATALOG_READ_ENABLED'  => 'false',
            'CATALOG_WRITE_ENABLED' => 'false',
            'CATALOG_HOOK_FAIL'     => '1',
            'CATALOG_METRICS_FAIL'  => '1',
            'CATALOG_BACKEND_URL'   => 'http://127.0.0.1:1',
        ]);
        self::assertSame(0, $status);
        self::assertSame(['valid' => true, 'errors' => [], 'warnings' => []], $report);
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testInvalidOriginAndMissingSecretFailEvenWithFlagsOff(): void
    {
        [$status, $report] = $this->check([
            'STRANGLER_TOKEN'       => '',
            'CATALOG_READ_ENABLED'  => 'false',
            'CATALOG_WRITE_ENABLED' => 'false',
            'CATALOG_BACKEND_URL'   => 'https://user:private-secret@example.test/path',
        ]);
        self::assertSame(1, $status);
        self::assertFalse($report['valid']);
        self::assertCount(2, $report['errors']);
        self::assertStringNotContainsString('private-secret', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testInvalidTimeoutReportsRuntimeFallback(): void
    {
        [$status, $report] = $this->check([
            'STRANGLER_TOKEN'     => 'preflight-secret',
            'CATALOG_BACKEND_URL' => 'http://127.0.0.1:1',
            'CATALOG_TIMEOUT'     => '-1',
        ]);
        self::assertSame(0, $status);
        self::assertTrue($report['valid']);
        self::assertCount(1, $report['warnings']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{int, array{valid: bool, errors: list<string>, warnings: list<string>}}
     */
    private function check(array $overrides): array
    {
        $environment = getenv();
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/examples/catalog/check.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_replace($environment, $overrides));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $errors);
        self::assertIsString($output);
        /** @var array{valid: bool, errors: list<string>, warnings: list<string>} $report */
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return [$status, $report];
    }
}
