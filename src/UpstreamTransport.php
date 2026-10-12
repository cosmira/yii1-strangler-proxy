<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Send upstream HTTP attempts and emit translated responses.
 *
 * @internal
 *
 * @phpstan-import-type Config from Strangler
 */
final readonly class UpstreamTransport
{
    /**
     * Request or response field containing the HTTP body.
     */
    private const BODY = 'body';

    /**
     * Response field containing the HTTP status code.
     */
    private const STATUS = 'status';

    /**
     * Response field containing the upstream HTTP headers.
     */
    private const HEADERS = 'headers';

    /**
     * Outgoing request field containing the resolved upstream path.
     */
    private const PATH = 'path';

    /**
     * Safe diagnostic category for a completed attempt.
     */
    private const REASON = 'reason';

    /**
     * Actual HTTP attempt duration in milliseconds.
     */
    private const TIME_MS = 'time_ms';

    /**
     * Outgoing request field containing the mapped HTTP method.
     */
    private const METHOD = 'method';

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
        ?StranglerAdapterInterface $adapter,
        string $routeTemplate,
    ): void {
        $settings = YiiRuntime::settings();
        $baseUri = YiiRuntime::baseUri($settings);
        if ($baseUri === '') {
            YiiRuntime::logRejection(
                $config, $actionKey, $routeTemplate, 'strangler_not_configured',
            );
            $this->emitter->error(500, 'strangler_not_configured', $adapter, $actionKey);

            return;
        }

        $this->dispatch($settings, $config, $actionKey, $outgoing, $adapter, $routeTemplate);
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
        ?StranglerAdapterInterface $adapter,
        string $routeTemplate,
    ): void {
        $method = $outgoing[self::METHOD];
        $path = $outgoing[self::PATH];
        $options = self::validatedOptions(
            $settings, $config, $actionKey, $outgoing, $routeTemplate,
        );
        $client = $this->client ?? new Client();
        $startedAt = ($this->clock)();

        try {
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException) {
            $timeMs = $this->elapsed($startedAt);
            YiiRuntime::logResult($config, $actionKey, $routeTemplate, [
                self::REASON => 'transport_failure',
                self::METHOD => $method, self::TIME_MS => $timeMs,
            ]);
            $this->notifyAttempt(
                $config, $actionKey, $outgoing, $routeTemplate, [self::TIME_MS => $timeMs],
            );
            $this->emitFailure($config, $actionKey, $routeTemplate, $timeMs, $method, $adapter);

            return;
        }

        $responseSnapshot = [
            self::STATUS  => $response->getStatusCode(),
            self::BODY    => (string) $response->getBody(),
            self::HEADERS => $response->getHeaders(),
        ];
        $timeMs = $this->elapsed($startedAt);
        $this->notifyAttempt($config, $actionKey, $outgoing, $routeTemplate, [
            'upstream_status' => $responseSnapshot[self::STATUS], self::TIME_MS => $timeMs,
        ]);
        $this->emitResponse(
            $config, $actionKey, $routeTemplate, $timeMs, $responseSnapshot, $method, $adapter,
        );
    }

    /**
     * Validate transport configuration before timing or sending an attempt.
     *
     * @param array<array-key, mixed> $settings
     * @param Config                  $config
     * @param array{method: string, path: string, query: array<array-key, mixed>,
     *     payload: array<array-key, mixed>, body: string|null} $outgoing
     *
     * @return array<array-key, mixed>
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
                $settings, $outgoing[self::METHOD], $outgoing['query'],
                $outgoing['payload'], $outgoing[self::BODY],
            );
        } catch (Throwable $exception) {
            YiiRuntime::logRejection(...[...$rejection, 'transport_configuration_failure']);

            throw $exception;
        }
    }

    /**
     * Build trusted headers and transport options for the upstream request.
     *
     * @param array<array-key, mixed> $settings
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function requestOptions(
        array $settings,
        string $method,
        array $query,
        array $payload,
        ?string $body,
    ): array {
        $headers = array_merge(HttpHeaders::request($settings), [
            'X-User-Id'          => YiiRuntime::userId(),
            'X-Strangler'        => '1',
            'X-Strangler-Locale' => YiiRuntime::application()->getLanguage(),
            'Accept'             => 'application/json',
        ]);

        $options = [
            RequestOptions::HEADERS         => $headers,
            RequestOptions::QUERY           => $query,
            'base_uri'                      => YiiRuntime::baseUri($settings),
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::VERIFY          => YiiRuntime::tlsVerification($settings),
            RequestOptions::TIMEOUT         => self::timeout($settings, 'timeout', 10),
            RequestOptions::CONNECT_TIMEOUT => self::timeout($settings, 'connect_timeout', 3),
        ];

        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $options;
        }

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $options[RequestOptions::HEADERS] = $headers;
            $options[RequestOptions::BODY] = $body;

            return $options;
        }

        $options[RequestOptions::JSON] = $payload;

        return $options;
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
        $result[self::METHOD] = $outgoing[self::METHOD];

        try {
            YiiRuntime::afterAttempt($config, $outgoing[self::METHOD], $outgoing[self::PATH]);
        } catch (Throwable $exception) {
            YiiRuntime::logResult(
                $config, $actionKey, $routeTemplate, [self::REASON => 'callback_failure'] + $result,
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
        ?StranglerAdapterInterface $adapter,
    ): void {
        $this->emitResponse($config, $actionKey, $routeTemplate, $timeMs, [
            self::STATUS  => 503,
            self::BODY    => '{"state":"error","error":"service_temporarily_unavailable"}',
            self::HEADERS => ['Content-Type' => ['application/json; charset=utf-8']],
        ], $method, $adapter, false);
    }

    /**
     * Translate and log the actual upstream and client statuses before writing output.
     *
     * @param Config                                                                  $config
     * @param array{status: int, body: string, headers: array<string, array<string>>} $response
     */
    private function emitResponse(
        array $config,
        string $actionKey,
        string $routeTemplate,
        int $timeMs,
        array $response,
        string $method,
        ?StranglerAdapterInterface $adapter,
        bool $hasResponse = true,
    ): void {
        $result = [self::TIME_MS => $timeMs, self::METHOD => $method];
        if ($hasResponse) {
            $result['upstream_status'] = $response[self::STATUS];
        }

        try {
            $legacyResponse = $adapter?->transformResponse(
                $actionKey, $response[self::STATUS],
                $response[self::BODY], $response[self::HEADERS],
            ) ?? $response;
        } catch (Throwable $exception) {
            YiiRuntime::logResult(
                $config, $actionKey, $routeTemplate,
                [self::REASON => 'adaptation_failure'] + $result,
            );

            throw $exception;
        }
        YiiRuntime::logResult($config, $actionKey, $routeTemplate, [
            self::REASON => 'forwarded', 'client_status' => $legacyResponse[self::STATUS],
        ] + $result);
        $this->emitter->send(
            $legacyResponse[self::STATUS],
            $legacyResponse[self::BODY], $legacyResponse[self::HEADERS],
            $hasResponse ? $timeMs : 0,
        );
    }
}
