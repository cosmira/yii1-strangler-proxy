<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Response\ErrorResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ErrorResponseTest extends TestCase
{
    public function testValidationMessagesUseNativeNamesAndTheYiiEnvelope(): void
    {
        $map = new ErrorResponse(['name' => 'DISPLAY_NAME'], []);
        $response = $map((object) ['errors' => (object) [
            'name' => ['required'], 'contacts.0.name' => ['invalid'],
        ]], new Response(422));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'state' => 'error', 'error' => 'validation', 'meta' => [
                'DISPLAY_NAME'            => ['required' => []],
                'CONTACTS.0.DISPLAY_NAME' => ['invalid' => []],
            ],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testExplicitYiiCodesRemainIntactExceptInternalFailures(): void
    {
        $map = new ErrorResponse([], [500 => 'internal_error']);
        $body = ['state' => 'error', 'error' => 'application_conflict', 'meta' => ['id' => 'item']];
        $response = $map($body, new Response(409));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($body, json_decode((string) $response->getBody(), true));
        $response = $map($body, new Response(500));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['state' => 'error', 'error' => 'internal_error'], json_decode((string) $response->getBody(), true));
    }

    #[DataProvider('ordinaryFailures')]
    public function testOrdinaryFailuresUseExplicitCodesBeforeConfiguredDefaults(
        mixed $body, int $status, string $code,
    ): void {
        $map = new ErrorResponse([], [403 => 'access_deny', 503 => 'service_temporarily_unavailable']);
        $response = $map($body, new Response($status));
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['state' => 'error', 'error' => $code], json_decode((string) $response->getBody(), true));
    }

    /** @return iterable<string, array{mixed, int, string}> */
    public static function ordinaryFailures(): iterable
    {
        yield 'native authorization code' => [new stdClass(), 403, 'access_deny'];
        yield 'unavailable without internal message' => [['message' => 'private detail'], 503, 'service_temporarily_unavailable'];
        yield 'explicit application error' => [['error' => 'duplicate'], 409, 'duplicate'];
        yield 'explicit application code' => [['code' => 'denied'], 409, 'denied'];
        yield 'unmapped status message' => [['message' => 'unprocessable'], 418, 'unprocessable'];
        yield 'fallback without message' => [[], 418, 'service_temporarily_unavailable'];
    }

    #[DataProvider('invalidErrors')]
    public function testMalformedUpstreamErrorsFailBeforeNativeFormatting(mixed $body): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        (new ErrorResponse([], []))($body, new Response(422));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidErrors(): iterable
    {
        yield 'scalar body' => ['invalid'];
        yield 'list body' => [['invalid']];
        yield 'invalid code' => [['error' => []]];
        yield 'scalar errors' => [['errors' => 'invalid']];
        yield 'list errors' => [['errors' => ['invalid']]];
        yield 'numeric field' => [['errors' => [1 => ['invalid']]]];
        yield 'scalar messages' => [['errors' => ['name' => 'required']]];
        yield 'non-string message' => [['errors' => ['name' => [null]]]];
    }
}
