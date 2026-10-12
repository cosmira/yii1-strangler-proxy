<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/** A real HTTP check of the published example; no test framework is required. */
final class CatalogContracts
{
    private array $servers = [];
    private array $directories = [];
    private int $passed = 0;
    private array $environment = [];
    private string $backendAddress = '';
    private string $legacyAddress = '';
    private string $directory = '';

    public function run(): void
    {
        try {
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$reads, $writes]) {
                $this->combination($reads, $writes);
            }
            echo "All catalog contracts passed. ({$this->passed} checks)\n";
        } finally {
            $this->stopServers();
            foreach ($this->directories as $directory) {
                $this->removeDirectory($directory);
            }
        }
    }

    private function combination(bool $reads, bool $writes): void
    {
        $this->stopServers();
        $directory = sys_get_temp_dir().'/strangler-catalog-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create example storage.');
        }
        $this->directories[] = $directory;
        $backendAddress = $this->address();
        $legacyAddress = $this->address();
        $environment = array_merge(getenv(), [
            'CATALOG_RUNTIME_DIR'   => $directory,
            'CATALOG_BACKEND_URL'   => 'http://'.$backendAddress,
            'CATALOG_READ_ENABLED'  => $reads ? 'true' : 'false',
            'CATALOG_WRITE_ENABLED' => $writes ? 'true' : 'false',
            'STRANGLER_TOKEN'       => 'catalog-contract-token',
            'CATALOG_TENANT'        => 'alpha',
            'CATALOG_TIMEOUT'       => '2',
            'CATALOG_FAULT_DELAY'   => '2',
        ]);
        $this->directory = $directory;
        $this->backendAddress = $backendAddress;
        $this->legacyAddress = $legacyAddress;
        $this->environment = $environment;
        $this->configure([]);
        $client = new Client([
            'base_uri'    => 'http://'.$legacyAddress, 'timeout' => 5,
            'http_errors' => false, 'allow_redirects' => false, 'cookies' => new CookieJar(),
        ]);
        echo 'Flags read='.(int) $reads.' write='.(int) $writes."\n";
        $cases = require __DIR__.'/contract-cases.php';
        $cases($this, $client, 'http://'.$backendAddress, $directory, $reads, $writes);
    }

    public function configure(array $overrides): void
    {
        $this->stopServers();
        $this->environment = array_merge($this->environment, $overrides);
        $this->server($this->backendAddress, 'backend.php', $this->environment, $this->directory.'/backend.log');
        $this->server($this->legacyAddress, 'legacy.php', $this->environment, $this->directory.'/legacy.log');
    }

    public function check(bool $condition, string $name): void
    {
        if (! $condition) {
            throw new RuntimeException('FAIL '.$name);
        }
        $this->passed++;
        echo '  PASS '.$name."\n";
    }

    public function json(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException('HTTP '.$response->getStatusCode().' returned invalid JSON: '.(string) $response->getBody(), previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON object or array.');
        }

        return $decoded;
    }

    private function address(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate local HTTP port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Cannot read local HTTP port.');
        }

        return $address;
    }

    private function server(string $address, string $router, array $environment, string $log): void
    {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, '-S', $address, __DIR__.'/'.$router,
        ], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes,
            dirname(__DIR__, 2), $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start '.$router);
        }
        fclose($pipes[0]);
        $this->servers[] = $process;
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($router.' did not start: '.file_get_contents($log));
    }

    private function stopServers(): void
    {
        foreach ($this->servers as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->servers = [];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new FilesystemIterator($directory) as $entry) {
            if ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}

try {
    (new CatalogContracts())->run();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
