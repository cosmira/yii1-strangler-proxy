<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\JsonResource;
use Cosmira\Strangler\Record;
use Cosmira\Strangler\Strangler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ConventionResponseTest extends TestCase
{
    public function testResponseExtensionsRetainConfiguredNamesValuesAndFinalValidation(): void
    {
        $validated = [];
        $validator = static function (ResponseInterface $response) use (&$validated): void {
            $validated[] = [$response->getStatusCode(), (string) $response->getBody()];
        };
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(fields: ['note' => 'COMMENT'])
            ->response(values: ['note' => static function (mixed $value): string {
                self::assertIsString($value);

                return strtoupper($value);
            }])
            ->response(using: static fn (JsonResource $resource): JsonResource => $resource
                ->configureRecord(static fn (Record $record): Record => $record->except(['private_count']))
                ->status(202)
                ->validateUsing($validator))
            ->response(using: static fn (JsonResource $resource): JsonResource => $resource
                ->configureRecord(static fn (Record $record): Record => $record
                    ->attributesUsing(static function (array $attributes): array {
                        self::assertIsString($attributes['note']);
                        $attributes['note'] .= '!';

                        return $attributes;
                    })))
            ->get('view', '/items/{id}');
        $first = $this->mapping($builder);
        $second = $this->mapping($builder->response(fields: ['note' => 'TEXT'])
            ->response(values: ['note' => static function (mixed $value): string {
                self::assertIsString($value);

                return strtolower($value);
            }]));
        $upstream = new Response(200, ['X-Request-Id' => 'retained'],
            '{"data":{"id":"i","note":"Hello","private_count":3,"new_field":false}}');
        $before = $first->response($upstream);
        $after = $second->response($upstream);

        self::assertSame('{"state":"data","data":{"ITEM_ID":"i","COMMENT":"HELLO!","NEW_FIELD":false}}',
            (string) $before->getBody());
        self::assertSame('{"state":"data","data":{"ITEM_ID":"i","TEXT":"hello!","NEW_FIELD":false}}',
            (string) $after->getBody());
        self::assertSame('retained', $before->getHeaderLine('X-Request-Id'));
        self::assertSame([
            [202, (string) $before->getBody()], [202, (string) $after->getBody()],
        ], $validated);
    }

    public function testResponseConfigurationRequiresTheConvention(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Strangler::proxy('catalog')
            ->response(using: static fn (JsonResource $resource): JsonResource => $resource)
            ->get('view', '/items/{id}')->build();
    }

    private function mapping(Strangler $builder): InlineMapping
    {
        $config = $builder->build()['config'];

        return new InlineMapping($config['mappings'] ?? [], [
            'action' => 'view', 'query' => [], 'payload' => null,
        ]);
    }
}
