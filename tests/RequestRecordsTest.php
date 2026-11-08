<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use PHPUnit\Framework\TestCase;

final class RequestRecordsTest extends TestCase
{
    public function testFallbackKeysPreserveOrderZeroAndMalformedContainers(): void
    {
        self::assertSame(['parent', 'fallback', 0, null, 'invalid'], Mapping::pluck([
            ['PARENT_ID' => 'parent', 'ID' => 'other'],
            ['PARENT_ID' => null, 'ID' => 'fallback'],
            ['ID' => 0], [], 'invalid',
        ], ['PARENT_ID', 'ID']));
        self::assertSame('invalid', Mapping::pluck('invalid', ['ID']));
        self::assertSame(['bad-key' => 'parent'], Mapping::pluck(['bad-key' => ['ID' => 'parent']], ['ID']));
    }

    public function testOrdinaryNestedNamesChangeButOpaqueMetadataAndJsonKindRemainIntact(): void
    {
        $metadata = (object) ['OriginalName' => false];
        $input = [(object) ['VALUE' => 'alice@example.test', 'META' => $metadata], false];
        $output = Mapping::requestRecords($input, Fields::uppercase());

        self::assertEquals([(object) ['value' => 'alice@example.test', 'meta' => $metadata], false], $output);
        self::assertSame('invalid', Mapping::requestRecords('invalid', Fields::uppercase()));
        self::assertSame(['bad-key' => ['value' => 1]], Mapping::requestRecords(['bad-key' => ['VALUE' => 1]], Fields::uppercase()));
    }

    public function testExplicitSelectionDropsMissingIdentifiersWithoutDroppingZeroOrEmptyNote(): void
    {
        $rows = [
            ['STATUS_ID' => 'first', 'NOTE' => ''], [],
            ['STATUS_ID' => null], ['STATUS_ID' => 0], 'malformed',
        ];
        $result = Mapping::requestRecords($rows, ['STATUS_ID' => 'status_id', 'NOTE' => 'note'],
            defaults: ['note' => null], required: ['status_id']);

        self::assertSame([
            ['status_id' => 'first', 'note' => ''],
            ['status_id' => 0, 'note' => null], 'malformed',
        ], $result);
    }

    public function testAmbiguousNestedNamesDoNotReachTheNewApi(): void
    {
        $config = Strangler::proxy('records')->uppercase('RECORD_ID')
            ->request(values: ['contacts' => static fn (mixed $rows): mixed => Mapping::requestRecords($rows, Fields::uppercase())])
            ->post('create', '/records')->build()['config'];
        $payload = ['CONTACTS' => [['VALUE' => 'first', 'value' => 'second']]];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'create', 'query' => [], 'payload' => $payload]);

        self::assertFalse($mapper->request([], $payload, null));
    }
}
