<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Response;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/** Encode a mapped body without retaining metadata for the upstream bytes. */
final class Json
{
    /**
     * Read a JSON object as arrays for application callbacks that need associative values.
     *
     * @return array<array-key, mixed>
     */
    public static function record(mixed $value): array
    {
        if (! $value instanceof \stdClass
            && (! is_array($value) || ($value !== [] && array_is_list($value)))) {
            throw new InvalidUpstreamResponse('Expected a JSON record object.');
        }

        return self::arrays((array) $value);
    }

    /**
     * Convert nested objects without interpreting their keys as resource attributes.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function arrays(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => is_array($value)
            || $value instanceof \stdClass ? self::arrays((array) $value) : $value, $values);
    }

    /**
     * Serialize JSON with the original status unless the caller replaces it.
     */
    public static function response(
        ResponseInterface $response,
        mixed $body,
        ?int $status = null,
    ): ResponseInterface {
        $responseStatus = $status ?? $response->getStatusCode();
        if ($responseStatus < 200 || in_array($responseStatus, [204, 205, 304], true)) {
            if ($body !== null) {
                throw new InvalidArgumentException('This HTTP status does not permit a JSON body.');
            }

            return new Response($responseStatus, self::headers($response), '');
        }

        return new Response(
            $responseStatus,
            ['Content-Type' => ['application/json; charset=utf-8']] + self::headers($response),
            json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    /**
     * Preserve only metadata that remains valid for a rewritten body.
     *
     * @return array<string, array<string>>
     */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach (['X-Request-Id', 'Retry-After', 'X-RateLimit-Remaining'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeader($name);
            }
        }

        return $headers;
    }
}
