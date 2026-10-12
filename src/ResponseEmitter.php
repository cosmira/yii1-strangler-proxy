<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use Closure;

/**
 * Yii's HTTP output boundary; injectable writers make header effects observable.
 */
final readonly class ResponseEmitter
{
    /**
     * Stable state value used in the legacy JSON error contract.
     */
    private const ERROR_STATE = 'error';

    /**
     * Write one response header through the configured output boundary.
     *
     * @var Closure(string, bool): void
     */
    private Closure $writeHeader;

    /**
     * Write the HTTP status through the configured output boundary.
     *
     * @var Closure(int): mixed
     */
    private Closure $writeStatus;

    /**
     * Use PHP's native writers by default, or supply an alternate HTTP boundary.
     *
     * @param (callable(string, bool): void)|null $writeHeader
     * @param (callable(int): mixed)|null         $writeStatus
     */
    public function __construct(?callable $writeHeader = null, ?callable $writeStatus = null)
    {
        $this->writeHeader = Closure::fromCallable($writeHeader ?? 'header');
        $this->writeStatus = Closure::fromCallable($writeStatus ?? 'http_response_code');
    }

    /**
     * Forward end-to-end response headers, then complete the Yii request.
     *
     * @param array<string, array<string>> $headers
     */
    public function send(int $status, string $body, array $headers, int $timeMs): void
    {
        $written = [];
        foreach (HttpHeaders::response($headers) as $name => $values) {
            $normalized = strtolower($name);
            foreach ($values as $value) {
                $replace = ! ($written[$normalized] ?? false);
                ($this->writeHeader)(sprintf('%s: %s', $name, $value), $replace);
                $written[$normalized] = true;
            }
        }

        ($this->writeHeader)('X-Strangler: 1', true);
        if (YII_DEBUG) {
            ($this->writeHeader)('X-Strangler-Time: '.$timeMs, true);
        }

        ($this->writeStatus)($status);
        echo $body;
        StranglerProxy::application()->end();
    }

    /**
     * Emit the package's stable JSON error contract.
     */
    public function error(
        int $status,
        string $code,
        ?StranglerAdapterInterface $adapter = null,
        string $actionId = '',
    ): void {
        if ($adapter !== null) {
            $body = json_encode(
                ['state' => self::ERROR_STATE, 'error' => $code], JSON_THROW_ON_ERROR,
            );
            $headers = ['Content-Type' => ['application/json; charset=utf-8']];
            $response = $adapter->transformResponse($actionId, $status, $body, $headers);
            $this->send($response['status'], $response['body'], $response['headers'], 0);

            return;
        }

        ($this->writeStatus)($status);
        ($this->writeHeader)('Content-Type: application/json; charset=utf-8', true);
        ($this->writeHeader)('X-Strangler: 1', true);
        echo json_encode(['state' => self::ERROR_STATE, 'error' => $code], JSON_UNESCAPED_UNICODE);
        StranglerProxy::application()->end();
    }
}
