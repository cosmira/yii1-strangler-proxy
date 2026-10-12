<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use InvalidArgumentException;

/**
 * Keep client credentials and transport headers outside the trusted backend hop.
 */
final class HttpHeaders
{
    /**
     * Transport headers that must not cross the proxy boundary.
     *
     * @var list<string>
     */
    private const TRANSPORT_HEADERS = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'proxy-connection', 'te', 'trailer', 'transfer-encoding', 'upgrade',
        'host', 'content-length',
    ];

    /**
     * Forward explicitly allowed client headers and apply server-owned values.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return array<string, string>
     */
    public static function request(array $settings): array
    {
        $incoming = self::incoming();
        $blocked = self::blocked($incoming);
        $client = self::clientHeaders($incoming, self::allowlist($settings), $blocked);
        $headers = array_replace($client, self::configured($settings));
        $blocked = self::blocked($headers);
        $result = [];
        foreach ($headers as $name => $value) {
            $isBlocked = $blocked[$name] ?? false;
            if (! $isBlocked) {
                $canonicalName = implode('-', array_map(ucfirst(...), explode('-', $name)));
                $result[$canonicalName] = $value;
            }
        }

        return $result;
    }

    /**
     * Preserve repeated end-to-end response headers without bridging backend cookies.
     *
     * @param array<string, array<string>> $headers
     *
     * @return array<string, array<string>>
     */
    public static function response(array $headers): array
    {
        $blocked = self::blocked($headers);
        $blocked['set-cookie'] = true;
        $result = [];
        foreach ($headers as $name => $values) {
            $headerName = strtolower($name);
            $isBlocked = $blocked[$headerName] ?? false;
            if (! $isBlocked) {
                foreach ($values as $value) {
                    self::validate($name, $value);
                }
                $result[$name] = $values;
            }
        }

        return $result;
    }

    /**
     * Normalize client HTTP headers without reading unrelated server variables.
     *
     * @return array<string, string>
     */
    private static function incoming(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Read the explicit client header allowlist.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return array<string, bool>
     */
    private static function allowlist(array $settings): array
    {
        $default = ['Accept-Language', 'X-Request-Id', 'Idempotency-Key'];
        $allowed = $settings['forward_headers'] ?? $default;
        if (! is_array($allowed)) {
            throw new InvalidArgumentException('Strangler forward_headers must be an array.');
        }
        $allowlist = [];
        foreach ($allowed as $name) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Strangler forward_headers must contain names.');
            }
            self::validate($name, '');
            $allowlist[strtolower($name)] = true;
        }

        return $allowlist;
    }

    /**
     * Accept selected client values without trusting identity or backend token headers.
     *
     * @param array<string, string> $incoming
     * @param array<string, bool>   $allowlist
     * @param array<string, bool>   $blocked
     *
     * @return array<string, string>
     */
    private static function clientHeaders(array $incoming, array $allowlist, array $blocked): array
    {
        foreach (['x-user-id', 'x-strangler', 'x-strangler-locale', 'x-strangler-token'] as $name) {
            $blocked[$name] = true;
        }
        $headers = [];
        foreach ($incoming as $name => $value) {
            $isAllowed = $allowlist[$name] ?? false;
            $isBlocked = $blocked[$name] ?? false;
            if ($isAllowed && ! $isBlocked) {
                self::validate($name, $value);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Read server-owned header values independently from the client allowlist.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return array<string, string>
     */
    private static function configured(array $settings): array
    {
        $configured = $settings['headers'] ?? [];
        if (! is_array($configured)) {
            throw new InvalidArgumentException('Strangler headers must be an array.');
        }
        $headers = [];
        foreach ($configured as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new InvalidArgumentException('Strangler headers must map names to strings.');
            }
            self::validate($name, $value);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * Include transport headers named by each Connection header.
     *
     * @param array<string, string|array<string>> $headers
     *
     * @return array<string, bool>
     */
    private static function blocked(array $headers): array
    {
        $blocked = array_fill_keys(self::TRANSPORT_HEADERS, true);
        foreach ($headers as $name => $values) {
            $headerName = strtolower($name);
            if ($headerName !== 'connection') {
                continue;
            }
            $value = is_array($values) ? implode(',', $values) : $values;
            foreach (explode(',', $value) as $token) {
                $blocked[strtolower(trim($token))] = true;
            }
        }

        return $blocked;
    }

    /**
     * Reject invalid header names and line-breaking values before emission.
     */
    private static function validate(string $name, string $value): void
    {
        $validName = preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/iD', $name);
        $hasLineBreak = strpbrk($value, "\r\n") !== false;
        if (! $validName || $hasLineBreak) {
            throw new InvalidArgumentException('Strangler headers require valid names and values.');
        }
    }
}
