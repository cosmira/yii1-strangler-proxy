<?php

declare(strict_types=1);

// Infection's proc_nice(1) bootstrap can emit a permission warning on macOS.
// PHPUnit interprets that warning in isolated tests as a killed mutant.
$temporaryDirectory = null;
$originalScanDirectory = getenv('PHP_INI_SCAN_DIR');
if (PHP_OS_FAMILY === 'Darwin' && function_exists('proc_nice')) {
    $temporaryDirectory = sys_get_temp_dir().'/strangler-infection-'.bin2hex(random_bytes(8));
    if (! mkdir($temporaryDirectory, 0700)) {
        fwrite(STDERR, "Cannot create Infection's temporary PHP configuration.\n");
        exit(1);
    }

    $disabledFunctions = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $disabledFunctions[] = 'proc_nice';
    if (file_put_contents($temporaryDirectory.'/zz-infection.ini', 'disable_functions='.implode(',', $disabledFunctions)."\n") === false) {
        if (is_file($temporaryDirectory.'/zz-infection.ini')) {
            unlink($temporaryDirectory.'/zz-infection.ini');
        }
        rmdir($temporaryDirectory);
        fwrite(STDERR, "Cannot write Infection's temporary PHP configuration.\n");
        exit(1);
    }
    $scanDirectory = $originalScanDirectory === false ? PHP_CONFIG_FILE_SCAN_DIR : $originalScanDirectory;
    putenv('PHP_INI_SCAN_DIR='.($scanDirectory === '' ? $temporaryDirectory : $scanDirectory.PATH_SEPARATOR.$temporaryDirectory));
}

try {
    $command = [
        PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'pcov.enabled=1',
        '-d', 'pcov.directory=.', '-d', 'xdebug.mode=coverage',
        dirname(__DIR__).'/vendor/bin/infection',
        '--only-covering-test-cases', '--with-timeouts', '--max-timeouts=0',
        ...array_slice($argv, 1),
    ];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__));
    if (! is_resource($process)) {
        fwrite(STDERR, "Cannot start Infection.\n");
        $status = 1;
    } else {
        $status = proc_close($process);
    }
} finally {
    if ($temporaryDirectory !== null) {
        unlink($temporaryDirectory.'/zz-infection.ini');
        rmdir($temporaryDirectory);
        putenv($originalScanDirectory === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR='.$originalScanDirectory);
    }
}

exit($status);
