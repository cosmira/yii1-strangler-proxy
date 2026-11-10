<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Format;
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

    public function testNestedValuesAreFormattedAfterRenamingWithoutChangingOtherRecords(): void
    {
        $meta = ['object_document_id' => 'document', 'MixedCase' => false];
        $map = Mapping::recordsWith(Fields::uppercase(), values: ['meta' => Format::jsonString(...)]);

        self::assertSame([
            ['meta' => json_encode($meta), 'value' => 'address'],
            ['meta' => null], ['value' => 'without metadata'], 'malformed',
        ], $map([
            ['META' => $meta, 'VALUE' => 'address'],
            ['META' => null], ['VALUE' => 'without metadata'], 'malformed',
        ]));
        self::assertEquals([(object) ['meta' => '{}']], $map([(object) ['META' => (object) []]]));
        self::assertSame('invalid', $map('invalid'));
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

    public function testRawJsonMetadataIsEncodedBeforeItReachesTheNewApi(): void
    {
        $config = Strangler::proxy('records')->uppercase('RECORD_ID')
            ->request(values: ['contacts' => Mapping::recordsWith(
                Fields::uppercase(), values: ['meta' => Format::jsonString(...)],
            )])->post('create', '/records')->build()['config'];
        $mapping = (new InlineMapping($config['mappings'] ?? [], [
            'action' => 'create', 'query' => [], 'payload' => [],
        ]))
            ->withPayload([], '{"CONTACTS":[{"META":{"MixedCase":false}}]}');

        $result = $mapping->request([], [], null);

        self::assertIsArray($result);
        self::assertArrayHasKey('payload', $result);
        self::assertJsonStringEqualsJsonString(
            json_encode(['contacts' => [['meta' => '{"MixedCase":false}']]], JSON_THROW_ON_ERROR),
            json_encode($result['payload'], JSON_THROW_ON_ERROR),
        );
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
