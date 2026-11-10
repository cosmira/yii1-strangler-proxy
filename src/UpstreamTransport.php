<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use Cosmira\Strangler\Internal\Http\HttpHeaders;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Send upstream HTTP attempts and emit translated responses.
 *
 * @internal
 *
 * @phpstan-import-type Config from Strangler
 *
 * @phpstan-type Options array{
 *     timeout: float, connect_timeout: float, query: array<array-key, mixed>, ...
 * }
 */
final readonly class UpstreamTransport
{
    /**
     * Configure the HTTP client, response output and elapsed-time clock.
     *
     * @param Closure(): float $clock
     */
    public function __construct(
        /**
         * HTTP client override, created lazily when omitted.
         */
        private ?ClientInterface $client,
        /**
         * Output boundary for successful and failed responses.
         */
        private ResponseEmitter $emitter,
        /**
         * Clock used to measure upstream request duration.
         *
         * @var Closure(): float
         */
        private Closure $clock,
    ) {}

    /**
     * Send one upstream attempt and translate its response.
     *
     * @param Config $config
     * @param array{method: string, path: string, query: array<array-key, mixed>,
     *     payload: array<array-key, mixed>, body: string|null} $outgoing
     */
    public function send(
        array $config,
        string $actionKey,
        array $outgoing,
        string $routeTemplate,
        ?InlineMapping $inline = null,
    ): void {
        $settings = YiiRuntime::settings();
        $baseUri = YiiRuntime::baseUri($settings);
        if ($baseUri === '') {
            YiiRuntime::logRejection(
                $config, $actionKey, $routeTemplate, 'strangler_not_configured',
            );
            $this->emitter->error(500, 'strangler_not_configured', $inline);

            return;
        }

        $this->dispatch(
            $settings, $config, $actionKey, $outgoing, $routeTemplate, $inline,
        );
    }

    /**
     * Execute and observe exactly one HTTP attempt after configuration guards.
     *
     * @param array<array-key, mixed> $settings
     * @param Config                  $config
     * @param array{method: string, path: string, query: array<array-key, mixed>,
     *     payload: array<array-key, mixed>, body: string|null} $outgoing
     */
    private function dispatch(
        array $settings,
        array $config,
        string $actionKey,
        array $outgoing,
        string $routeTemplate,
        ?InlineMapping $inline,
    ): void {
        $options = self::validatedOptions(
            $settings, $config, $actionKey, $outgoing, $routeTemplate,
        );
        $client = $this->client ?? new Client();
        $startedAt = ($this->clock)();
        $collection = $inline?->collection();
        $deadline = $startedAt + $options['timeout'];

        do {
            if ($collection !== null) {
                $options = $collection->options($options, $deadline - ($this->clock)());
                if ($options === null) {
                    $this->emitFailure($config, $actionKey, $routeTemplate,
                        $this->elapsed($startedAt), $outgoing['method'], $inline);

                    return;
                }
            }

            [$response, $timeMs] = $this->attempt(
                $client, $config, $actionKey, $outgoing, $routeTemplate, $options, $startedAt,
            );
            if ($response === null) {
                $this->emitFailure(
                    $config, $actionKey, $routeTemplate, $timeMs, $outgoing['method'], $inline,
                );

                return;
            }
            $complete = $collection === null ? $response : $collection->response($response);
        } while ($complete === null);

        $snapshot = $this->responseSnapshot($complete, $inline);
        $this->emitResponse(
            $config, $actionKey, $routeTemplate, $timeMs, $snapshot,
            $outgoing['method'], $response->getStatusCode(), $inline,
        );
    }

    /**
     * Observe one actual HTTP attempt, preserving callback exceptions outside the transport catch.
     *
     * @param Config                                   $config
     * @param array{method: string, path: string, ...} $outgoing
     * @param Options                                  $options
     *
     * @return array{ResponseInterface|null, int}
     */
    private function attempt(
        ClientInterface $client, array $config, string $actionKey, array $outgoing,
        string $routeTemplate, array $options, float $startedAt,
    ): array {
        try {
            $response = $client->request($outgoing['method'], $outgoing['path'], $options);
        } catch (GuzzleException) {
            $timeMs = $this->elapsed($startedAt);
            YiiRuntime::logResult($config, $actionKey, $routeTemplate, [
                'reason' => 'transport_failure',
                'method' => $outgoing['method'], 'time_ms' => $timeMs,
            ]);
            $this->notifyAttempt(
                $config, $actionKey, $outgoing, $routeTemplate, ['time_ms' => $timeMs],
            );

            return [null, $timeMs];
        }
        $timeMs = $this->elapsed($startedAt);
        $this->notifyAttempt($config, $actionKey, $outgoing, $routeTemplate, [
            'upstream_status' => $response->getStatusCode(), 'time_ms' => $timeMs,
        ]);

        return [$response, $timeMs];
    }

    /**
     * Retain a readable body for adaptation while rewinding seekable upstream streams.
     *
     * @return array{status: int, body: string|StreamInterface,
     *     headers: array<string, array<string>>}
     */
    private function responseSnapshot(ResponseInterface $response, ?InlineMapping $inline): array
    {
        $mapsResponse = $inline !== null && $inline->mapsResponse($response->getStatusCode());
        $snapshot = [
            'status'  => $response->getStatusCode(),
            'body'    => $mapsResponse ? (string) $response->getBody() : $response->getBody(),
            'headers' => $response->getHeaders(),
        ];
        if ($response->getBody()->isSeekable()) {
            Message::rewindBody($response);
        }

        return $snapshot;
    }

    /**
     * Validate transport configuration before timing or sending an attempt.
     *
     * @param array<array-key, mixed> $settings
     * @param Config                  $config
     * @param array{method: string, path: string, query: array<array-key, mixed>,
     *     payload: array<array-key, mixed>, body: string|null} $outgoing
     *
     * @return Options
     */
    private static function validatedOptions(
        array $settings,
        array $config,
        string $actionKey,
        array $outgoing,
        string $routeTemplate,
    ): array {
        $rejection = [$config, $actionKey, $routeTemplate];

        try {
            return self::requestOptions(
                $settings, $outgoing['method'], $outgoing['query'],
                $outgoing['payload'], $outgoing['body'],
                YiiRuntime::fileMappings($config, $actionKey),
            );
        } catch (Throwable $exception) {
            YiiRuntime::logRejection(...[...$rejection, 'transport_configuration_failure']);

            throw $exception;
        }
    }

    /**
     * Build trusted headers and transport options for the upstream request.
     *
     * @param array<array-key, mixed>    $settings
     * @param array<array-key, mixed>    $query
     * @param array<array-key, mixed>    $payload
     * @param array<string, string>|null $files
     *
     * @return Options
     */
    private static function requestOptions(
        array $settings,
        string $method,
        array $query,
        array $payload,
        ?string $body,
        ?array $files = null,
    ): array {
        $headers = array_merge(HttpHeaders::request($settings), [
            'X-User-Id'          => YiiRuntime::userId(),
            'X-Strangler'        => '1',
            'X-Strangler-Locale' => YiiRuntime::application()->getLanguage(),
            'Accept'             => 'application/json',
        ]);

        $options = [
            'headers'                       => $headers,
            'query'                         => $query,
            'base_uri'                      => YiiRuntime::baseUri($settings),
            'http_errors'                   => false,
            'allow_redirects'               => false,
            'verify'                        => YiiRuntime::tlsVerification($settings),
            'timeout'                       => self::timeout($settings, 'timeout', 10),
            'connect_timeout'               => self::timeout($settings, 'connect_timeout', 3),
        ];

        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $options;
        }

        if ($files !== null) {
            unset($headers['Content-Type']);
            $options['headers'] = $headers;
            $options['multipart'] = self::multipart($payload, $files);

            return $options;
        }

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = $body;

            return $options;
        }

        $options['json'] = $payload;

        return $options;
    }

    /**
     * Build scalar fields and streamed uploads without forwarding failed or unselected files.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, string>   $files
     *
     * @return list<array{name: array-key, contents: scalar|open-resource|closed-resource|null,
     *     filename?: string}>
     */
    private static function multipart(array $payload, array $files): array
    {
        $parts = [];
        foreach ($payload as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Multipart form fields must be scalar values.');
            }
            $parts[] = [
                'name'     => $name,
                'contents' => is_bool($value) ? (int) $value : $value,
            ];
        }
        foreach ($files as $source => $target) {
            $file = \CUploadedFile::getInstanceByName($source);
            if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }
            $parts[] = [
                'name'     => $target,
                'contents' => Utils::tryFopen($file->getTempName(), 'rb'),
                'filename' => $file->getName(),
            ];
        }

        return $parts;
    }

    /**
     * Read numeric timeout settings, retaining defaults for invalid input.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function timeout(array $settings, string $name, float $default): float
    {
        $value = $settings[$name] ?? $default;

        if (! is_numeric($value)) {
            return $default;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0 ? $number : $default;
    }

    /**
     * Measure the completed HTTP attempt independently of application callbacks.
     */
    private function elapsed(float $startedAt): int
    {
        return (int) round((($this->clock)() - $startedAt) * 1000);
    }

    /**
     * Preserve the mandatory hook's exception while recording safe attempt context.
     *
     * @param Config                                     $config
     * @param array{method: string, path: string, ...}   $outgoing
     * @param array{upstream_status?: int, time_ms: int} $result
     */
    private function notifyAttempt(
        array $config,
        string $actionKey,
        array $outgoing,
        string $routeTemplate,
        array $result,
    ): void {
        $result['method'] = $outgoing['method'];

        try {
            YiiRuntime::afterAttempt($config, $outgoing['method'], $outgoing['path']);
        } catch (Throwable $exception) {
            YiiRuntime::logResult(
                $config, $actionKey, $routeTemplate, ['reason' => 'callback_failure'] + $result,
            );

            throw $exception;
        }
    }

    /**
     * Translate a transport error through the same observable response boundary.
     *
     * @param Config $config
     */
    private function emitFailure(
        array $config,
        string $actionKey,
        string $routeTemplate,
        int $timeMs,
        string $method,
        ?InlineMapping $inline,
    ): void {
        $this->emitResponse($config, $actionKey, $routeTemplate, $timeMs, [
            'status'  => 503,
            'body'    => '{"state":"error","error":"service_temporarily_unavailable"}',
            'headers' => ['Content-Type' => ['application/json; charset=utf-8']],
        ], $method, null, $inline);
    }

    /**
     * Translate and log the actual upstream and client statuses before writing output.
     *
     * @param Config $config
     * @param array{status: int, body: string|StreamInterface,
     *     headers: array<string, array<string>>} $response
     */
    private function emitResponse(
        array $config,
        string $actionKey,
        string $routeTemplate,
        int $timeMs,
        array $response,
        string $method,
        ?int $upstreamStatus,
        ?InlineMapping $inline = null,
    ): void {
        $result = ['time_ms' => $timeMs, 'method' => $method];
        if ($upstreamStatus !== null) {
            $result['upstream_status'] = $upstreamStatus;
        }

        try {
            if ($inline !== null && is_string($response['body'])) {
                $mapped = $inline->response(new Response(
                    $response['status'], $response['headers'], $response['body'],
                ));
                ($config['controllerContract'] ?? null)?->validateResponse($mapped);
                $legacyResponse = [
                    'status'  => $mapped->getStatusCode(),
                    'body'    => (string) $mapped->getBody(),
                    'headers' => $mapped->getHeaders(),
                ];
            } else {
                $legacyResponse = $response;
            }
        } catch (Throwable $exception) {
            YiiRuntime::logResult(
                $config, $actionKey, $routeTemplate,
                ['reason' => 'adaptation_failure'] + $result,
            );

            throw $exception;
        }
        YiiRuntime::logResult($config, $actionKey, $routeTemplate, [
            'reason' => 'forwarded', 'client_status' => $legacyResponse['status'],
        ] + $result);
        if (is_string($legacyResponse['body'])) {
            $this->emitter->send(
                $legacyResponse['status'], $legacyResponse['body'], $legacyResponse['headers'],
                $upstreamStatus === null ? 0 : $timeMs,
            );
        } else {
            $this->emitter->sendStream(
                $legacyResponse['status'], $legacyResponse['body'], $legacyResponse['headers'],
                $upstreamStatus === null ? 0 : $timeMs,
            );
        }
    }
}
