<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use CLogger;
use Cosmira\Strangler\Internal\Http\HttpHeaders;
use Cosmira\Strangler\Response\Record;
use CWebApplication;
use InvalidArgumentException;
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
     * Application parameter name and logging category for the proxy.
     */
    private const CATEGORY = 'strangler';

    /**
     * Filter configuration key identifying the migration feature.
     */
    private const FEATURE = 'feature';

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

    public static function controllerContract(): ?ControllerContract
    {
        if (! class_exists(Yii::class, false) || ! Yii::app() instanceof CWebApplication) {
            return null;
        }
        $class = self::settings()['controller_contract'] ?? null;
        if ($class === null) {
            return null;
        }
        if (! is_string($class) || ! is_subclass_of($class, ControllerContract::class)) {
            throw new InvalidArgumentException('controller_contract must name a ControllerContract class.');
        }

        return new $class();
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
    private static function isEnabled(?string $feature): bool
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
     * Check and explain the feature gate for this filter candidate.
     *
     * @param Config $config
     */
    public static function allowsFeature(array $config, string $actionKey): bool
    {
        if (! self::isEnabled($config[self::FEATURE] ?? null)) {
            self::logDecision($config, $actionKey, 'feature_disabled');

            return false;
        }

        return true;
    }

    /**
     * Log request details when debugging or request logging is enabled.
     */
    private static function debugLog(string $message): void
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
            (string) ($config[self::FEATURE] ?? ''),
            $actionKey,
            self::logPath($path),
            $error,
            self::requestId(),
        ), CLogger::LEVEL_WARNING, self::CATEGORY);
    }

    /**
     * Describe this filter candidate without assuming the remaining chain's executor.
     *
     * @param Config $config
     */
    public static function logDecision(array $config, string $actionKey, string $reason): void
    {
        self::debugLog(sprintf(
            'Strangler candidate | feature: %s | action: %s | reason: %s'
                .' | selected: false | request_id: %s',
            (string) ($config[self::FEATURE] ?? ''), $actionKey, $reason, self::requestId(),
        ));
    }

    /**
     * Record safe attempt metadata; error records remain visible without request logging.
     *
     * @param Config $config
     * @param array{reason: string, method: string, upstream_status?: int,
     *     client_status?: int, time_ms: int} $result
     */
    public static function logResult(
        array $config,
        string $actionKey,
        string $path,
        array $result,
    ): void {
        $message = sprintf(
            'Strangler attempt | feature: %s | action: %s | method: %s | path: %s'
                .' | reason: %s | selected: true | upstream_status: %s'
                .' | client_status: %s | time_ms: %s | request_id: %s',
            (string) ($config[self::FEATURE] ?? ''), $actionKey,
            $result['method'], self::logPath($path),
            $result['reason'], $result['upstream_status'] ?? '', $result['client_status'] ?? '',
            $result['time_ms'], self::requestId(),
        );
        if ($result['reason'] !== 'forwarded') {
            Yii::log($message, CLogger::LEVEL_ERROR, self::CATEGORY);

            return;
        }

        self::debugLog($message);
    }

    /**
     * Reuse the validated, forwarded correlation ID, including server overrides.
     */
    private static function requestId(): string
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
     * Verify HTTPS by default, allowing an explicit development opt-out or CA bundle.
     *
     * @param array<array-key, mixed> $settings
     */
    public static function tlsVerification(array $settings): bool|string
    {
        $value = $settings['verify_ssl'] ?? true;
        if (is_bool($value) || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw new InvalidArgumentException('verify_ssl must be a boolean or a CA bundle path.');
    }

    /**
     * Omit route query strings and fragments from diagnostic templates.
     */
    private static function logPath(string $path): string
    {
        return explode('#', explode('?', $path)[0])[0];
    }

    /**
     * Select action-specific uploads before the shared file mapping.
     *
     * @param Config $config
     *
     * @return array<string, string>|null
     */
    public static function fileMappings(array $config, string $actionKey): ?array
    {
        $files = $config['files'] ?? [];

        return $files[$actionKey] ?? $files['*'] ?? null;
    }
}
