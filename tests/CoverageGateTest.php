<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageGateTest extends TestCase
{
    #[DataProvider('coverageReports')]
    public function testMarkerClassesDoNotInflateCoverageOrHideUncoveredCode(
        int $coveredMethods,
        int $coveredStatements,
        int $projectClasses,
        int $expectedExit,
    ): void {
        $report = tempnam(sys_get_temp_dir(), 'strangler-clover-');
        self::assertIsString($report);
        $xml = <<<XML
            <coverage><project>
                <file name="Marker.php"><class name="Marker"><metrics methods="0" coveredmethods="0" statements="0" coveredstatements="0"/></class></file>
                <file name="Executable.php"><class name="Executable"><metrics methods="1" coveredmethods="{$coveredMethods}" statements="1" coveredstatements="{$coveredStatements}"/></class></file>
                <metrics classes="{$projectClasses}" methods="1" coveredmethods="{$coveredMethods}" statements="1" coveredstatements="{$coveredStatements}"/>
            </project></coverage>
            XML;
        file_put_contents($report, $xml);
        $environment = getenv();
        unset($environment['GITHUB_STEP_SUMMARY']);

        try {
            $pipes = [];
            $process = proc_open([PHP_BINARY, dirname(__DIR__).'/tools/check-coverage.php', $report],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame($expectedExit, proc_close($process), $output);
            if ($expectedExit === 0) {
                self::assertStringContainsString('Classes: 100.00% (1/1)', $output);
                self::assertStringContainsString('Methods: 100.00% (1/1)', $output);
                self::assertStringContainsString('Lines: 100.00% (1/1)', $output);
            } else {
                self::assertStringContainsString('Coverage must be exactly 100%', $output);
            }
        } finally {
            unlink($report);
        }
    }

    /** @return list<array{int, int, int, int}> */
    public static function coverageReports(): array
    {
        return [[1, 1, 1, 0], [0, 1, 1, 1], [1, 0, 1, 1], [1, 1, 2, 1]];
    }
}
