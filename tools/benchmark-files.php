<?php

declare(strict_types=1);

use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use Cosmira\Strangler\UpstreamTransport;
use GuzzleHttp\Client;

if (PHP_SAPI === 'cli-server') {
    $directory = getenv('STRANGLER_BENCH_DIR');
    $size = $_GET['size'] ?? '';
    if (! is_string($directory) || ! in_array($size, ['1', '16'], true)) {
        http_response_code(400);

        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = $_FILES['photo']['tmp_name'] ?? '';
        if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(400);

            return;
        }
        header('Content-Type: application/json');
        echo json_encode(['bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR);

        return;
    }
    header('Content-Type: application/octet-stream');
    readfile($directory.'/'.$size.'.bin');

    return;
}

require dirname(__DIR__).'/vendor/autoload.php';

if (! extension_loaded('curl')) {
    throw new RuntimeException('The published file benchmark requires the PHP cURL extension.');
}

if (($argv[1] ?? '') === '--worker') {
    [$script, $worker, $source, $address, $file, $operation, $size] = $argv;
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'Cosmira\\Strangler\\';
        if (str_starts_with($class, $prefix) && ! str_starts_with($class, $prefix.'Tests\\')) {
            require $source.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    }, true, true);
    define('YII_DEBUG', false);
    define('YII_ENABLE_ERROR_HANDLER', false);
    define('YII_ENABLE_EXCEPTION_HANDLER', false);
    require dirname(__DIR__).'/vendor/yiisoft/yii/framework/yii.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    $_SERVER['SCRIPT_NAME'] = '/benchmark-files.php';
    $_SERVER['REQUEST_URI'] = '/';
    $_GET = ['size' => $size];
    $_POST = [];
    new WebApplication([
        'basePath'   => dirname(__DIR__).'/tests', 'runtimePath' => sys_get_temp_dir(),
        'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
        'params'     => ['strangler' => ['base_uri' => $address, 'features' => ['files' => true]]],
    ]);
    $builder = Strangler::proxy('files')->mapQuery(static fn (array $query): array => $query);
    if ($operation === 'upload') {
        $_FILES = ['photo' => ['name' => 'file.bin', 'type' => 'application/octet-stream',
            'tmp_name'                => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]];
        CUploadedFile::reset();
        $builder->mapFiles(['photo' => 'photo'])->post('file', '/file');
    } else {
        $builder->get('file', '/file');
    }
    $config = $builder->build()['config'];
    $status = 0;
    $emitter = new ResponseEmitter(static fn () => null, static function (int $value) use (&$status): void {
        $status = $value;
    });
    $proxy = new StranglerProxy(new Client(['expect' => false]), $emitter);
    $transportFile = (new ReflectionClass(UpstreamTransport::class))->getFileName();
    if ($transportFile !== realpath($source.'/src/UpstreamTransport.php')) {
        throw new RuntimeException('The benchmark loaded a different package source.');
    }
    $run = static function () use ($proxy, $config, &$status, $operation, $file): array {
        $hash = hash_init('sha256');
        $bytes = 0;
        $reply = '';
        ob_start(static function (string $chunk) use ($hash, &$bytes, &$reply, $operation): string {
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
            if ($operation === 'upload') {
                $reply .= $chunk;
            }

            return '';
        }, 65536);
        memory_reset_peak_usage();
        $memory = memory_get_usage(true);
        $usage = getrusage();
        $start = hrtime(true);

        try {
            $proxy->handle(new Controller('files'), $config, 'file');
        } catch (ApplicationEnded) {
            // The real proxy ends its Yii request after writing the response.
        } finally {
            ob_end_flush();
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage(true);
        $extra = $peak - $memory;
        $finished = getrusage();
        $cpu = (($finished['ru_utime.tv_sec'] + $finished['ru_stime.tv_sec'])
            - ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'])) * 1000
            + (($finished['ru_utime.tv_usec'] + $finished['ru_stime.tv_usec'])
            - ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'])) / 1000;
        $digest = hash_final($hash);
        if ($operation === 'upload') {
            $result = json_decode($reply, true, flags: JSON_THROW_ON_ERROR);
            $bytes = $result['bytes'];
            $digest = $result['sha256'];
        }
        if ($status !== 200 || $bytes !== filesize($file) || $digest !== hash_file('sha256', $file)) {
            throw new RuntimeException('File transfer failed status, size or SHA-256 validation.');
        }

        return ['ms' => $elapsed, 'cpu_ms' => $cpu, 'peak_bytes' => $peak, 'extra_peak_bytes' => $extra, 'bytes' => $bytes, 'sha256' => $digest];
    };
    $run();
    echo json_encode($run(), JSON_THROW_ON_ERROR);
    exit;
}

$baseline = $argv[1] ?? null;
if ($baseline !== null && ! is_file($baseline.'/src/UpstreamTransport.php')) {
    throw new InvalidArgumentException('The baseline must be an extracted package checkout.');
}
$directory = sys_get_temp_dir().'/strangler-files-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) {
    throw new RuntimeException('Cannot reserve a loopback address.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = null;

try {
    $block = str_repeat('0123456789abcdef', 65536);
    foreach ([1, 16] as $size) {
        $file = fopen($directory.'/'.$size.'.bin', 'wb');
        for ($i = 0; $i < $size; $i++) {
            fwrite($file, $block);
        }
        fclose($file);
    }
    $server = proc_open([PHP_BINARY, '-d', 'post_max_size=64M', '-d', 'upload_max_filesize=64M',
        '-S', $address, __FILE__], [0 => ['file', '/dev/null', 'r'],
            1                         => ['file', $directory.'/server.log', 'a'], 2 => ['file', $directory.'/server.log', 'a']],
        $pipes, null, array_merge(getenv(), ['STRANGLER_BENCH_DIR' => $directory]));
    if (! is_resource($server)) {
        throw new RuntimeException('Cannot start the benchmark server.');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://'.$address, $error, $message, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (! $ready) {
        throw new RuntimeException('The benchmark server did not start.');
    }
    $sources = $baseline === null ? ['current' => dirname(__DIR__)]
        : ['baseline' => $baseline, 'current' => dirname(__DIR__)];
    $report = ['php' => PHP_VERSION, 'rounds' => 5, 'warmups_per_worker' => 1, 'cases' => []];
    foreach (['download', 'upload'] as $operation) {
        foreach ([1, 16] as $size) {
            $runs = [];
            for ($round = 0; $round < 5; $round++) {
                foreach ($sources as $label => $source) {
                    $process = proc_open([PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'opcache.enable_cli=1', '-d', 'display_errors=stderr', __FILE__, '--worker',
                        $source, 'http://'.$address, $directory.'/'.$size.'.bin', $operation, (string) $size],
                        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (! is_resource($process)) {
                        throw new RuntimeException('Cannot start a benchmark worker.');
                    }
                    $output = stream_get_contents($pipes[1]);
                    $errors = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    if (proc_close($process) !== 0) {
                        throw new RuntimeException('Benchmark worker failed: '.$errors);
                    }
                    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                    $runs[$label][] = $run;
                }
            }
            $report['cases'][] = ['operation' => $operation, 'mib' => $size, 'runs' => $runs];
        }
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
