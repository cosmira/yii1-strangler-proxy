<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CException;
use CLogger;
use CWebApplication;
use LogicException;
use Yii;

/**
 * Access Yii application settings, components and proxy diagnostics.
 *
 * @internal
 *
 * @phpstan-import-type Config from Strangler
 */
final class YiiRuntime
{
    /**
     * Yii component configuration field naming the adapter implementation.
     */
    private const COMPONENT_CLASS = 'class';

    /**
     * Application parameter name and logging category for the proxy.
     */
    private const CATEGORY = 'strangler';

    /**
     * Require the web application used by this Yii controller adapter.
     */
    public static function application(): CWebApplication
    {
        $application = Yii::app();
        if (! $application instanceof CWebApplication) {
            throw new LogicException('Strangler requires a Yii web application.');
        }

        return $application;
    }

    /**
     * Read proxy settings from the Yii application parameters.
     *
     * @return array<array-key, mixed>
     */
    public static function settings(): array
    {
        $settings = self::application()->getParams()->itemAt(self::CATEGORY) ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Validate the configured upstream HTTP origin.
     *
     * @param array<array-key, mixed> $settings
     */
    public static function baseUri(array $settings): string
    {
        $uri = $settings['base_uri'] ?? '';
        if (! is_string($uri)) {
            return '';
        }

        $uri = trim($uri);
        if (preg_match('~^https?://[^/?#@]+/?$~', $uri) !== 1
            || filter_var($uri, FILTER_VALIDATE_URL) === false
        ) {
            return '';
        }

        return rtrim($uri, '/');
    }

    /**
     * Read the named feature flag from application settings.
     */
    public static function isEnabled(?string $feature): bool
    {
        if ($feature === null || $feature === '') {
            return false;
        }

        $strangler = self::settings();
        $features = $strangler['features'] ?? [];
        if (! is_array($features)) {
            return false;
        }

        return ($features[$feature] ?? false) === true;
    }

    /**
     * Determine whether an adapter was explicitly configured.
     */
    private static function hasAdapterConfig(mixed $adapterConfig): bool
    {
        return ! ($adapterConfig === null || $adapterConfig === '' || $adapterConfig === []);
    }

    /**
     * Resolve an instance or Yii component configuration.
     */
    public static function resolveAdapter(mixed $adapterConfig): ?StranglerAdapterInterface
    {
        if (! self::hasAdapterConfig($adapterConfig)) {
            return null;
        }

        if ($adapterConfig instanceof StranglerAdapterInterface) {
            return $adapterConfig;
        }

        $componentConfig = is_string($adapterConfig)
            ? [self::COMPONENT_CLASS => $adapterConfig]
            : $adapterConfig;

        if (! is_array($componentConfig)
            || ! is_string($componentConfig[self::COMPONENT_CLASS] ?? null)
        ) {
            Yii::log(
                'Strangler adapter config must contain class',
                CLogger::LEVEL_WARNING,
                self::CATEGORY,
            );

            return null;
        }

        return self::createAdapter($componentConfig);
    }

    /**
     * Instantiate and validate an application-owned Yii adapter.
     *
     * @param array{class: string, ...} $adapterConfig
     */
    private static function createAdapter(array $adapterConfig): ?StranglerAdapterInterface
    {
        try {
            $adapter = Yii::createComponent($adapterConfig);
        } catch (CException $exception) {
            Yii::log(
                sprintf(
                    'Strangler adapter could not be created: %s | error: %s',
                    $adapterConfig[self::COMPONENT_CLASS],
                    $exception->getMessage(),
                ),
                CLogger::LEVEL_WARNING,
                self::CATEGORY,
            );

            return null;
        }

        if (! $adapter instanceof StranglerAdapterInterface) {
            Yii::log(
                sprintf(
                    'Strangler adapter must implement StranglerAdapterInterface: %s',
                    $adapterConfig[self::COMPONENT_CLASS],
                ),
                CLogger::LEVEL_WARNING,
                self::CATEGORY,
            );

            return null;
        }

        return $adapter;
    }

    /**
     * Log request details when debugging or request logging is enabled.
     */
    public static function debugLog(string $message): void
    {
        $settings = self::settings();

        $debug = YII_DEBUG;
        $logRequests = $settings['log_requests'] ?? false;
        if (! $debug && ! $logRequests) {
            return;
        }

        Yii::log($message, CLogger::LEVEL_INFO, self::CATEGORY);
    }

    /**
     * Explain configuration guards without including credentials or payloads.
     *
     * @param Config $config
     */
    public static function logRejection(
        array $config,
        string $actionKey,
        string $path,
        string $error,
    ): void {
        Yii::log(sprintf(
            'Strangler request rejected | feature: %s | action: %s | path: %s'
                .' | error: %s | request_id: %s',
            (string) ($config['feature'] ?? ''),
            $actionKey,
            $path,
            $error,
            self::requestId(),
        ), CLogger::LEVEL_WARNING, self::CATEGORY);
    }

    /**
     * Reuse the validated, forwarded correlation ID, including server overrides.
     */
    public static function requestId(): string
    {
        $headers = HttpHeaders::request(self::settings());

        return $headers['X-Request-Id'] ?? '';
    }

    /**
     * Convert only scalar Yii user identifiers to HTTP header values.
     */
    public static function userId(): string
    {
        $id = self::application()->getUser()->getId();

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * Notify the application after a completed or failed transport attempt.
     *
     * @param Config $config
     */
    public static function afterAttempt(array $config, string $method, string $path): void
    {
        $callback = $config['afterAttempt'] ?? null;
        if ($callback !== null) {
            $callback($method, $path);
        }
    }

    /**
     * Record an upstream transport failure without exposing request payloads.
     *
     * @param Config $config
     */
    public static function logFailure(
        array $config,
        string $actionKey,
        string $path,
        string $error,
    ): void {
        Yii::log(sprintf(
            'Strangler request failed | feature: %s | request_id: %s'
                .' | action: %s | path: %s | error: %s',
            (string) ($config['feature'] ?? ''),
            self::requestId(),
            $actionKey,
            $path,
            $error,
        ), CLogger::LEVEL_ERROR, self::CATEGORY);
    }
}
