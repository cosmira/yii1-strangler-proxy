<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;

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
    ): void {
        $settings = YiiRuntime::settings();
        $baseUri = YiiRuntime::baseUri($settings);
        if ($baseUri === '') {
            YiiRuntime::logRejection(
                $config, $actionKey, $outgoing[self::PATH], 'strangler_not_configured',
            );
            $this->emitter->error(500, 'strangler_not_configured', $adapter, $actionKey);

            return;
        }

        $method = $outgoing['method'];
        $path = $outgoing[self::PATH];
        $startedAt = ($this->clock)();

        try {
            $client = $this->client ?? new Client();

            $options = self::requestOptions(
                $settings,
                $method,
                $outgoing['query'],
                $outgoing['payload'],
                $outgoing[self::BODY],
            );
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException $exception) {
            YiiRuntime::afterAttempt($config, $method, $path);

            YiiRuntime::logFailure($config, $actionKey, $path, $exception->getMessage());

            $this->emitter->error(503, 'service_temporarily_unavailable', $adapter, $actionKey);

            return;
        }

        $responseSnapshot = [
            self::STATUS  => $response->getStatusCode(),
            self::BODY    => (string) $response->getBody(),
            self::HEADERS => $response->getHeaders(),
        ];
        YiiRuntime::afterAttempt($config, $method, $path);
        $this->emitResponse(
            $config, $actionKey, $outgoing, $baseUri, $startedAt,
            $responseSnapshot, $adapter,
        );
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
            RequestOptions::VERIFY          => self::tlsVerification($settings),
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
     * Verify HTTPS by default, allowing an explicit development opt-out or CA bundle.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function tlsVerification(array $settings): bool|string
    {
        $value = $settings['verify_ssl'] ?? true;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new InvalidArgumentException('verify_ssl must be a boolean or a CA bundle path.');
    }

    /**
     * Translate the response, record elapsed time and write it to the legacy client.
     *
     * @param Config $config
     * @param array{method: string, path: string, query: array<array-key, mixed>,
     *     payload: array<array-key, mixed>, body: string|null} $outgoing
     * @param array{status: int, body: string, headers: array<string, array<string>>} $response
     */
    private function emitResponse(
        array $config,
        string $actionKey,
        array $outgoing,
        string $baseUri,
        float $startedAt,
        array $response,
        ?StranglerAdapterInterface $adapter,
    ): void {
        $legacyResponse = $adapter?->transformResponse(
            $actionKey,
            $response[self::STATUS],
            $response[self::BODY],
            $response[self::HEADERS],
        ) ?? $response;

        $timeMs = (int) round((($this->clock)() - $startedAt) * 1000);

        YiiRuntime::debugLog(sprintf(
            'Strangler request proxied | feature: %s | action: %s | method: %s'
                .' | base_uri: %s | path: %s | status: %s | time_ms: %s | request_id: %s',
            (string) ($config['feature'] ?? ''),
            $actionKey,
            $outgoing['method'],
            $baseUri,
            $outgoing[self::PATH],
            $legacyResponse[self::STATUS],
            $timeMs,
            YiiRuntime::requestId(),
        ));

        $this->emitter->send(
            $legacyResponse[self::STATUS],
            $legacyResponse[self::BODY],
            $legacyResponse[self::HEADERS],
            $timeMs,
        );
    }
}
