<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/** Encode a mapped body without retaining metadata for the upstream bytes. */
final class Json
{
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
