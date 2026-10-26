<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Json;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testMappedResponsePreservesJsonShapesAndOnlySafeMetadata(): void
    {
        $upstream = new Response(201, [
            'Content-Type' => ['text/plain'], 'Content-Length' => ['500'], 'Content-Encoding' => ['gzip'],
            'ETag'         => ['old'], 'Digest' => ['old'], 'Content-Digest' => ['old'], 'Last-Modified' => ['old'],
            'X-Request-Id' => ['one', 'two'], 'Retry-After' => ['10'], 'X-RateLimit-Remaining' => ['9'], 'X-Private' => ['secret'],
        ], 'original');
        $body = (object) ['items' => [], 'errors' => (object) [], 'nullable' => null, 'id' => '9223372036854775808', 'float' => 1.0, 'name' => 'Стол'];
        $response = Json::response($upstream, $body);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"items":[],"errors":{},"nullable":null,"id":"9223372036854775808","float":1.0,"name":"Стол"}', (string) $response->getBody());
        self::assertSame([
            'Content-Type' => ['application/json; charset=utf-8'], 'X-Request-Id' => ['one', 'two'],
            'Retry-After'  => ['10'], 'X-RateLimit-Remaining' => ['9'],
        ], $response->getHeaders());
        self::assertSame('original', (string) $upstream->getBody());
        self::assertSame('old', $upstream->getHeaderLine('ETag'));
        self::assertSame(200, Json::response($upstream, ['state' => 'data'], 200)->getStatusCode());
    }

    #[DataProvider('noBodyStatuses')]
    public function testNoBodyStatusesRemainEmptyAndCanBeExplicitlyReplaced(int $status): void
    {
        $upstream = new Response($status, ['X-Request-Id' => ['trace'], 'ETag' => ['old']]);
        $empty = Json::response($upstream, null);
        self::assertSame($status, $empty->getStatusCode());
        self::assertSame('', (string) $empty->getBody());
        self::assertSame(['X-Request-Id' => ['trace']], $empty->getHeaders());
        $legacy = Json::response($upstream, ['state' => 'data'], 200);
        self::assertSame(200, $legacy->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $legacy->getBody());
    }

    #[DataProvider('noBodyStatuses')]
    public function testNoBodyStatusCannotSilentlyDiscardMappedBody(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        Json::response(new Response($status), ['state' => 'data']);
    }

    /** @return list<array{int}> */
    public static function noBodyStatuses(): array
    {
        return [[103], [204], [205], [304]];
    }

    #[DataProvider('emptyJsonValues')]
    public function testNoBodyStatusRejectsEveryJsonValueExceptNull(mixed $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        Json::response(new Response(204), $body);
    }

    /** @return list<array{mixed}> */
    public static function emptyJsonValues(): array
    {
        return [[false], [0], [''], [[]], [(object) []]];
    }

    public function testEncodingFailurePropagatesInsteadOfPretendingTheUpstreamWasInvalid(): void
    {
        $this->expectException(JsonException::class);
        Json::response(new Response(), ['invalid' => "\xFF"]);
    }
}
