<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Internal\Http;

use Cosmira\Strangler\Response\Json;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Enforce a declared upstream success status for routed and intermediate requests.
 *
 * @internal
 */
final class ExpectedStatus
{
    /**
     * Reject invalid declarations before sending the request.
     */
    public static function validate(?int $expects): void
    {
        if ($expects !== null && ($expects < 200 || $expects >= 300)) {
            throw new InvalidArgumentException('Expected HTTP statuses must be in the 2xx range.');
        }
    }

    /**
     * Reject an unexpected success before reading or transforming its body.
     */
    public static function check(ResponseInterface $response, ?int $expects): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($expects !== null && $status >= 200 && $status < 300 && $status !== $expects) {
            return Json::invalidResponse($response);
        }

        return $response;
    }
}
