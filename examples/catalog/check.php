<?php

declare(strict_types=1);

// This application-owned check reads the same declarations as the example host.
// It does not start the host, create adapters, open storage or contact the backend.
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/vendor/yiisoft/yii/framework/yii.php';
require __DIR__.'/common.php';
require __DIR__.'/CatalogApiAdapter.php';
require __DIR__.'/proxy.php';

use Cosmira\Strangler\StranglerAdapterInterface;

$settings = catalogSettings();
$filters = catalogProxyFilters();
$errors = [];
$warnings = [];
$uri = $settings['base_uri'] ?? null;
if (! is_string($uri) || preg_match('~^https?://[^/?#@]+/?$~', trim($uri)) !== 1
    || filter_var(trim($uri), FILTER_VALIDATE_URL) === false) {
    $errors[] = 'params.strangler.base_uri: expected an HTTP(S) origin without credentials or path.';
}
foreach (['timeout' => 10, 'connect_timeout' => 3] as $field => $default) {
    $value = $settings[$field] ?? $default;
    if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
        $warnings[] = "params.strangler.{$field}: invalid value; runtime uses its default.";
    }
}
$verify = $settings['verify_ssl'] ?? true;
if (! is_bool($verify) && (! is_string($verify) || $verify === '')) {
    $errors[] = 'params.strangler.verify_ssl: expected a boolean or CA bundle path.';
} elseif (is_string($verify) && (! is_file($verify) || ! is_readable($verify))) {
    $errors[] = 'params.strangler.verify_ssl: CA bundle must be a readable file.';
}
$validateHeader = static function (mixed $name, mixed $value): bool {
    return is_string($name) && is_string($value)
        && preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/iD', $name) === 1
        && strpbrk($value, "\r\n") === false;
};
$headers = $settings['headers'] ?? [];
if (! is_array($headers)) {
    $errors[] = 'params.strangler.headers: expected a map of string headers.';
} else {
    foreach ($headers as $name => $value) {
        if (! $validateHeader($name, $value)) {
            $errors[] = 'params.strangler.headers: invalid header name or value (values omitted).';
        }
    }
}
$allowed = $settings['forward_headers'] ?? ['Accept-Language', 'X-Request-Id', 'Idempotency-Key'];
if (! is_array($allowed)) {
    $errors[] = 'params.strangler.forward_headers: expected a list of header names.';
} else {
    foreach ($allowed as $name) {
        if (! $validateHeader($name, '')) {
            $errors[] = 'params.strangler.forward_headers: invalid header name.';
        } elseif (strtolower($name) === 'x-tenant-id') {
            $errors[] = 'params.strangler.forward_headers: tenant context must come from the authenticated host.';
        }
    }
}
if (! is_string(getenv('STRANGLER_TOKEN')) || getenv('STRANGLER_TOKEN') === '') {
    $errors[] = 'STRANGLER_TOKEN: configure the shared channel secret (value omitted).';
}
foreach ($filters as $filter) {
    $config = $filter['config'];
    $feature = $config['feature'];
    $adapter = $config['adapter'];
    if (! is_string($adapter) || ! class_exists($adapter)
        || ! is_subclass_of($adapter, StranglerAdapterInterface::class)) {
        $errors[] = "{$feature}.adapter: expected an autoloadable contract adapter class.";
    } elseif ((new ReflectionClass($adapter))->isAbstract()) {
        $errors[] = "{$feature}.adapter: cannot use an abstract class.";
    }
    foreach (['payloadReader', 'bypass', 'afterAttempt'] as $field) {
        if (isset($config[$field]) && ! is_callable($config[$field])) {
            $errors[] = "{$feature}.{$field}: expected a callable.";
        }
    }
    foreach ($config['routes'] as $action => $route) {
        if (! in_array($route['method'], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $errors[] = "{$feature}.{$action}.method: unsupported outgoing method.";
        }
        if (! str_starts_with($route['path'], '/') || str_starts_with($route['path'], '//')) {
            $errors[] = "{$feature}.{$action}.path: expected a local upstream path.";
        }
    }
}
$report = ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
exit($errors === [] ? 0 : 1);
