<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\JsonResource;
use Cosmira\Strangler\Record;
use Cosmira\Strangler\Schema\Fields;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ResponseConfigurationTest extends TestCase
{
    public function testARecordCallbackCannotBeExtendedAsAConventionalSchema(): void
    {
        $resource = JsonResource::make()->recordUsing(static fn (array|\stdClass $record): array|\stdClass => $record);
        $this->expectException(\InvalidArgumentException::class);

        $resource->configureRecord(static fn (Record $record): Record => $record);
    }

    public function testValidatorReceivesFinalItemAndEmptyResponses(): void
    {
        $seen = [];
        $resource = JsonResource::make()->fields(Fields::uppercase())->status(202)
            ->validateUsing(static function (ResponseInterface $response) use (&$seen): void {
                $seen[] = [$response->getStatusCode(), (string) $response->getBody()];
            });
        $upstream = new Response(201, ['X-Request-Id' => 'kept']);
        $item = $resource((object) ['data' => (object) ['name' => 'Desk']], $upstream, ['action' => 'view']);
        $empty = $resource(null, $upstream, ['action' => 'delete']);

        self::assertSame([
            [202, '{"state":"data","data":{"NAME":"Desk"}}'],
            [202, '{"state":"data"}'],
        ], $seen);
        self::assertSame('kept', $item->getHeaderLine('X-Request-Id'));
        self::assertSame('kept', $empty->getHeaderLine('X-Request-Id'));
    }

    public function testValidatorExceptionsPropagate(): void
    {
        $resource = JsonResource::make()->validateUsing(static function (ResponseInterface $response): void {
            throw new RuntimeException('Invalid application contract.');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid application contract.');

        $resource(null, new Response(204), ['action' => 'view']);
    }

    public function testValidatorConfigurationCanBeForked(): void
    {
        $seen = [];
        $base = JsonResource::make()->validateUsing(static function (ResponseInterface $response) use (&$seen): void {
            $seen[] = 'base';
        });
        $fork = $base->validateUsing(static function (ResponseInterface $response) use (&$seen): void {
            $seen[] = 'fork';
        });

        $fork(null, new Response(204), ['action' => 'view']);
        $base(null, new Response(204), ['action' => 'view']);

        self::assertSame(['base', 'fork', 'base'], $seen);
    }
}
