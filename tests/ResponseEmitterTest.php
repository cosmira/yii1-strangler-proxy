<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Yii;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ResponseEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        define('YII_DEBUG', in_array($this->name(), [
            'testDebugResponsesExposeElapsedTime', 'testInlineGeneratedErrorsKeepTheirEnvelopeAndZeroAttemptTime',
        ], true));
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        new WebApplication([
            'basePath'    => __DIR__,
            'runtimePath' => __DIR__,
            'components'  => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
        ]);
        http_response_code(200);
    }

    public function testStreamedResponseUsesBoundedReadsAndLeavesTheStreamOpen(): void
    {
        $payload = str_repeat("file\x00", 40000);
        $source = Utils::streamFor($payload);
        $reads = [];
        $stream = FnStream::decorate($source, [
            'read' => static function (int $length) use ($source, &$reads): string {
                $reads[] = $length;

                return $source->read($length);
            },
            '__toString' => static fn () => throw new \RuntimeException('Do not buffer a file.'),
        ]);
        $events = [];
        $emitter = $this->observedEmitter($events);

        self::assertSame($payload, $this->capture(
            static fn () => $emitter->sendStream(200, $stream, ['X-File' => ['present']], 0),
        ));
        self::assertNotEmpty($reads);
        self::assertGreaterThan(1, count($reads));
        foreach ($reads as $length) {
            self::assertLessThanOrEqual(65536, $length);
        }
        self::assertTrue($source->isReadable());
        self::assertSame(['header', 'X-File: present', true], $events[0]);
    }

    public function testStalledResponseStreamFailsInsteadOfLoopingForever(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->expects(self::once())->method('read')->willReturn('');
        $events = [];
        $emitter = $this->observedEmitter($events);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The upstream response stream stopped before EOF.');
        $this->capture(static fn () => $emitter->sendStream(200, $stream, [], 0));
    }

    public function testForwardedResponsePreservesRepeatedHeadersAndReplacesItsMarker(): void
    {
        $events = [];
        $emitter = $this->observedEmitter($events);

        $body = $this->capture(static fn () => $emitter->send(207, 'response body', [
            'Set-Cookie'        => ['first=1', 'second=2'],
            'X-Upstream'        => ['first', 'second'],
            'x-upstream'        => ['third'],
            'Connection'        => ['keep-alive, X-Internal'],
            'X-Internal'        => ['transport-only'],
            'Keep-Alive'        => ['timeout=5'],
            'cOnTeNt-LeNgTh'    => ['999'],
            'TRANSFER-ENCODING' => ['chunked'],
            'X-Strangler'       => ['upstream-marker'],
        ], 17));

        self::assertSame('response body', $body);
        self::assertSame([
            ['header', 'X-Upstream: first', true],
            ['header', 'X-Upstream: second', false],
            ['header', 'x-upstream: third', false],
            ['header', 'X-Strangler: upstream-marker', true],
            ['header', 'X-Strangler: 1', true],
            ['status', 207, null],
        ], $events);
    }

    public function testDebugResponsesExposeElapsedTime(): void
    {
        $events = [];
        $emitter = $this->observedEmitter($events);

        self::assertSame('timed response', $this->capture(static fn () => $emitter->send(201, 'timed response', [], 37)));
        self::assertSame([
            ['header', 'X-Strangler: 1', true],
            ['header', 'X-Strangler-Time: 37', true],
            ['status', 201, null],
        ], $events);
    }

    public function testErrorResponsesExposeUnicodeJsonAndTheirHttpStatus(): void
    {
        $events = [];
        $emitter = $this->observedEmitter($events);

        $body = $this->capture(static fn () => $emitter->error(503, 'Сервис недоступен'));

        self::assertSame('{"state":"error","error":"Сервис недоступен"}', $body);
        self::assertSame([
            ['status', 503, null],
            ['header', 'Content-Type: application/json; charset=utf-8', true],
            ['header', 'X-Strangler: 1', true],
        ], $events);
    }

    public function testInlineGeneratedErrorsKeepTheirEnvelopeAndZeroAttemptTime(): void
    {
        $events = [];
        $emitter = $this->observedEmitter($events);
        $inline = new InlineMapping([
            'errors' => ['*' => static fn (mixed $json, ResponseInterface $response): ResponseInterface => $response],
        ], ['action' => 'update', 'query' => [], 'payload' => null]);

        self::assertSame('{"state":"error","error":"invalid_payload"}',
            $this->capture(static fn () => $emitter->error(400, 'invalid_payload', inline: $inline)));
        self::assertContains(['header', 'Content-Type: application/json; charset=utf-8', true], $events);
        self::assertContains(['header', 'X-Strangler-Time: 0', true], $events);
        self::assertContains(['status', 400, null], $events);
    }

    public function testDefaultWritersApplyTheStatusAndOutputBeforeEndingYii(): void
    {
        $emitter = new ResponseEmitter();

        self::assertSame('native response', $this->capture(static fn () => $emitter->send(202, 'native response', [], 0)));
        self::assertSame(202, http_response_code());
        self::assertSame('{"state":"error","error":"native_error"}', $this->capture(static fn () => $emitter->error(502, 'native_error')));
        self::assertSame(502, http_response_code());
    }

    /** @param list<array{string, string|int, bool|null}> $events */
    private function observedEmitter(array &$events): ResponseEmitter
    {
        return new ResponseEmitter(
            static function (string $header, bool $replace) use (&$events): void {
                $events[] = ['header', $header, $replace];
            },
            static function (int $status) use (&$events): void {
                $events[] = ['status', $status, null];
            },
        );
    }

    /** @param callable(): void $operation */
    private function capture(callable $operation): string
    {
        ob_start();

        try {
            $operation();
            self::fail('Response emission must end the Yii request.');
        } catch (ApplicationEnded) {
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
