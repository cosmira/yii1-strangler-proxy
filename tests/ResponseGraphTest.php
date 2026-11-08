<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Strangler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ResponseGraphTest extends TestCase
{
    public function testOneFluentObjectTranslatesRecursiveRelationsAndKeepsOpaqueJsonUntouched(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(fields: ['updated_at' => 'CHANGE_DATE'], except: ['internal'], relations: [
                'children' => ['identifier' => 'CHILD_ID', 'name' => 'childs', 'many' => true],
                'owner'    => ['identifier' => 'OWNER_ID', 'name' => 'owner', 'many' => false],
            ])
            ->get('view', '/items/{id}');
        $json = json_decode('{"data":{"id":"root","updated_at":"today","internal":1,"meta":{"originalKey":true},"children":[{"id":"child","internal":2,"children":[],"owner":{"id":"owner","owner":null}},{"id":"second","children":[],"owner":{"id":"second-owner","owner":null}}]}}');

        $mapped = $this->map($builder, $json);

        self::assertSame(['state' => 'data', 'data' => [
            'ITEM_ID' => 'root', 'CHANGE_DATE' => 'today', 'META' => ['originalKey' => true],
            'childs'  => [[
                'CHILD_ID' => 'child', 'childs' => [],
                'owner'    => ['OWNER_ID' => 'owner', 'owner' => null],
            ], ['CHILD_ID' => 'second', 'childs' => [], 'owner' => ['OWNER_ID' => 'second-owner', 'owner' => null]]],
        ]], $mapped);
    }

    public function testProjectionDoesNotLeakBetweenRecordsOrBuilds(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(
                optional: ['score'],
                projection: static fn (array $context): array => empty($context['query']) ? [] : ['children' => [], 'SCORE' => []],
            )
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID', 'name' => 'children', 'many' => true]])
            ->get('view', '/items/{id}');
        $json = json_decode('{"data":{"id":"root","score":7,"children":[{"id":"child","score":3}]}}');
        $first = $this->map($builder, $json);
        $second = $this->map($builder, $json, ['with' => ['children']]);

        self::assertSame(['ITEM_ID' => 'root'], $first['data']);
        self::assertSame(['ITEM_ID' => 'root', 'SCORE' => 7, 'children' => [['CHILD_ID' => 'child']]], $second['data']);
    }

    public function testValueDifferencesAndCallbacksApplyToEachRelatedRecordInOrder(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(
                values: ['name' => static fn (mixed $value): mixed => is_string($value) ? strtoupper($value) : $value],
                attributes: static fn (array $attributes, string $identifier): array => $attributes + ['kind' => $identifier],
                after: static fn (array $mapped, array $raw, ?array $projection, string $identifier, ?string $relation): array => $mapped + ['relation' => $relation],
            )
            ->response(relations: ['children' => ['identifier' => 'CHILD_ID', 'name' => 'children', 'many' => true]])
            ->get('view', '/items/{id}');
        $result = $this->map($builder, json_decode('{"data":{"id":"root","name":"alice","children":[{"id":"child","name":"bob"}]}}'));

        self::assertSame([
            'ITEM_ID'  => 'root', 'NAME' => 'ALICE', 'KIND' => 'ITEM_ID',
            'children' => [['CHILD_ID' => 'child', 'NAME' => 'BOB', 'KIND' => 'CHILD_ID', 'relation' => 'children']],
            'relation' => null,
        ], $result['data']);
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    private function map(Strangler $builder, mixed $json, array $query = []): array
    {
        $config = $builder->build()['config'];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'view', 'query' => $query, 'payload' => null]);
        $response = $mapper->response(new Response(200, [], json_encode($json, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
