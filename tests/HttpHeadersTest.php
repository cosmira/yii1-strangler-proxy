<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Internal\Http\HttpHeaders;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpHeadersTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testDefaultPolicyPassesRequestContextWithoutClientCredentials(): void
    {
        $_SERVER = [
            'HTTP_ACCEPT_LANGUAGE'   => 'ru',
            'HTTP_X_REQUEST_ID'      => 'request-42',
            'HTTP_IDEMPOTENCY_KEY'   => 'operation-42',
            'HTTP_COOKIE'            => 'PHPSESSID=legacy',
            'HTTP_AUTHORIZATION'     => 'Bearer client-token',
            'HTTP_X_STRANGLER_TOKEN' => 'spoofed',
            'HTTP_X_USER_ID'         => 'spoofed-user',
            'HTTP_X_UNRELATED'       => 'unrelated',
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_INVALID'         => [],
        ];

        self::assertSame([
            'Accept-Language' => 'ru',
            'X-Request-Id'    => 'request-42',
            'Idempotency-Key' => 'operation-42',
        ], HttpHeaders::request([]));
    }

    public function testExplicitAllowlistCannotTrustClientIdentityOrToken(): void
    {
        $_SERVER = [
            'HTTP_COOKIE'             => 'legacy=required',
            'HTTP_AUTHORIZATION'      => 'Bearer required',
            'HTTP_X_USER_ID'          => 'spoofed',
            'HTTP_X_STRANGLER'        => 'spoofed',
            'HTTP_X_STRANGLER_LOCALE' => 'spoofed',
            'HTTP_X_STRANGLER_TOKEN'  => 'spoofed',
            'HTTP_X_REQUEST_ID'       => 'not-allowed',
        ];

        self::assertSame([
            'Cookie'        => 'legacy=required',
            'Authorization' => 'Bearer required',
        ], HttpHeaders::request(['forward_headers' => [
            'COOKIE', 'authorization', 'X-User-Id', 'X-Strangler', 'X-Strangler-Locale', 'X-Strangler-Token',
        ]]));
        self::assertSame([], HttpHeaders::request(['forward_headers' => []]));
    }

    public function testServerMetadataCannotImpersonateAllowedClientHeaders(): void
    {
        $_SERVER = [
            'HTTP_X_REQUEST_ID' => 'client-request',
            'FAKE_X_REQUEST_ID' => 'server-metadata',
        ];

        self::assertSame(['X-Request-Id' => 'client-request'], HttpHeaders::request([]));
    }

    public function testServerHeadersOverrideClientValuesCaseInsensitivelyAndOwnTheToken(): void
    {
        $_SERVER = ['HTTP_X_REQUEST_ID' => 'client', 'HTTP_X_STRANGLER_TOKEN' => 'client-token'];

        self::assertSame([
            'X-Request-Id'      => 'server',
            'X-Strangler-Token' => 'server-token',
            'Authorization'     => 'Bearer backend',
        ], HttpHeaders::request(['headers' => [
            'x-request-ID'      => 'server',
            'x-strangler-token' => 'server-token',
            'authorization'     => 'Bearer backend',
        ]]));
    }

    public function testRequestPolicyRemovesTransportAndConnectionNamedHeadersFromBothSources(): void
    {
        $_SERVER = [
            'HTTP_CONNECTION'   => ' keep-alive, X-Transient ',
            'HTTP_X_TRANSIENT'  => 'client-only',
            'HTTP_X_REQUEST_ID' => 'stable',
        ];
        $configured = [
            'Connection'          => 'X-Server-Transient, TE',
            'X-Server-Transient'  => 'server-only',
            'Host'                => 'spoofed.test',
            'Content-Length'      => '999',
            'Keep-Alive'          => 'timeout=5',
            'Proxy-Authenticate'  => 'challenge',
            'Proxy-Authorization' => 'credential',
            'Proxy-Connection'    => 'keep-alive',
            'TE'                  => 'trailers',
            'Trailer'             => 'checksum',
            'Transfer-Encoding'   => 'chunked',
            'Upgrade'             => 'websocket',
        ];

        self::assertSame(['X-Request-Id' => 'stable'], HttpHeaders::request([
            'forward_headers' => ['Connection', 'X-Transient', 'X-Request-Id'],
            'headers'         => $configured,
        ]));
    }

    public function testClientConnectionMetadataCannotRemoveServerOwnedHeaders(): void
    {
        $_SERVER = [
            'HTTP_CONNECTION'   => 'X-Strangler-Token, X-Request-Id',
            'HTTP_X_REQUEST_ID' => 'spoofed',
        ];

        self::assertSame([
            'X-Strangler-Token' => 'server-token',
            'X-Request-Id'      => 'server-request',
        ], HttpHeaders::request(['headers' => [
            'X-Strangler-Token' => 'server-token',
            'X-Request-Id'      => 'server-request',
        ]]));
    }

    public function testResponsePolicyPreservesRepeatedHeadersAndDropsCookiesAndTransportMetadata(): void
    {
        self::assertSame([
            'Content-Type' => ['application/json'],
            'X-Upstream'   => ['one', 'two'],
        ], HttpHeaders::response([
            'cOnNeCtIoN'          => ['keep-alive, x-transient', 'X-Other'],
            'X-Transient'         => ['one'],
            'x-other'             => ['two'],
            'set-COOKIE'          => ['PHPSESSID=backend', 'backend=1'],
            'Host'                => ['backend.test'],
            'Content-Length'      => ['999'],
            'Keep-Alive'          => ['timeout=5'],
            'Proxy-Authenticate'  => ['challenge'],
            'Proxy-Authorization' => ['credential'],
            'Proxy-Connection'    => ['keep-alive'],
            'TE'                  => ['trailers'],
            'Trailer'             => ['checksum'],
            'Transfer-Encoding'   => ['chunked'],
            'Upgrade'             => ['websocket'],
            'Content-Type'        => ['application/json'],
            'X-Upstream'          => ['one', 'two'],
        ]));
        self::assertSame([], HttpHeaders::response([]));
    }

    /** @param array<array-key, mixed> $settings */
    #[DataProvider('invalidSettings')]
    public function testInvalidHeaderSettingsFailBeforeForwarding(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        HttpHeaders::request($settings);
    }

    /** @return list<array{array<array-key, mixed>}> */
    public static function invalidSettings(): array
    {
        return [
            [['forward_headers' => 'X-Request-Id']],
            [['headers' => 'token']],
            [['forward_headers' => [42]]],
            [['forward_headers' => ['invalid header']]],
            [['forward_headers' => ["X-Request-Id\n"]]],
            [['headers' => [42 => 'value']]],
            [['headers' => ['X-Token' => 42]]],
            [['headers' => ['' => 'value']]],
            [['headers' => ["X-Token\n" => 'value']]],
            [['headers' => ['X-Token' => "value\r\nX-Spoofed: yes"]]],
        ];
    }

    public function testAllowedClientHeadersCannotIntroduceAdditionalHeaderLines(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = "request\nX-Spoofed: yes";
        $this->expectException(InvalidArgumentException::class);
        HttpHeaders::request([]);
    }

    public function testResponseHeadersCannotIntroduceAdditionalHeaderLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HttpHeaders::response(['X-Upstream' => ["value\rX-Spoofed: yes"]]);
    }
}
