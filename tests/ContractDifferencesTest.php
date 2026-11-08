<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Convention;
use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContractDifferencesTest extends TestCase
{
    public function testFilterValuesAreExpandedAfterNamesAndListsAreTranslated(): void
    {
        $builder = Strangler::proxy('records')->uppercase('RECORD_ID')
            ->query(filters: ['kind' => 'type'], values: [
                'type' => static fn (mixed $value): mixed => Format::expand($value, ['Legacy' => ['Modern', 'modern']]),
            ])
            ->get('get', '/records')->build()['config'];
        $query = ['filter' => ['KIND' => ['Legacy', 'Other']]];
        $mapper = new InlineMapping($builder['mappings'] ?? [], ['action' => 'get', 'query' => $query, 'payload' => null]);
        $result = $mapper->request($query, [], null);

        self::assertIsArray($result);
        self::assertSame(['filter' => ['type' => 'Legacy,Modern,modern,Other'], 'page' => 1, 'per_page' => 20], $result['query']);
        self::assertSame('Legacy,Modern,modern,Other', Format::expand('Legacy,Modern,Other,Legacy', ['Legacy' => ['Modern', 'modern']]));
        self::assertNull(Format::expand(null, []));
    }

    public function testShapeDifferencesReuseNamingAndValueRulesAndRejectActualCollisions(): void
    {
        $config = Strangler::proxy('records')->uppercase('RECORD_ID')
            ->request(
                fields: ['PARENTS' => 'parent_ids', 'PARENT_ID' => 'parent_ids'],
                using: static function (array $payload, array $context, Convention $defaults): array|\stdClass|false {
                    if (array_key_exists('PARENT_ID', $payload)) {
                        $payload['PARENT_ID'] = [$payload['PARENT_ID']];
                    }

                    return $defaults->payload($payload);
                },
            )
            ->post('create', '/records')->build()['config'];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'create', 'query' => [], 'payload' => null]);
        $payload = ['PARENT_ID' => 'parent'];
        $result = $mapper->withPayload($payload)->request([], $payload, null);

        self::assertIsArray($result);
        self::assertSame(['parent_ids' => ['parent']], $result['payload']);
        $ambiguous = $payload + ['PARENTS' => ['other']];
        self::assertFalse($mapper->withPayload($ambiguous)->request([], $ambiguous, null));
    }

    public function testInvalidNestedValueNamesKeepTheNativeRequestBeforeAnyWrite(): void
    {
        $config = Strangler::proxy('records')->uppercase('RECORD_ID')
            ->request(values: ['nested' => static fn (): never => throw new InvalidArgumentException('Ambiguous nested names')])
            ->post('create', '/records')->build()['config'];
        $payload = ['NESTED' => ['VALUE' => 1, 'value' => 2]];
        $mapper = new InlineMapping($config['mappings'] ?? [], ['action' => 'create', 'query' => [], 'payload' => $payload]);

        self::assertFalse($mapper->request([], $payload, null));
    }

    public function testOperationAliasesKeepEverySourceNameInDeclarationOrder(): void
    {
        self::assertSame(['copy' => ['copy_item', 'add_item'], 'move' => ['move_item']],
            Mapping::reverseAliases(['copy_item' => 'copy', 'add_item' => 'copy', 'move_item' => 'move']));
        self::assertSame([], Mapping::reverseAliases([]));
    }

    public function testChangesBecomeSnapshotsWithoutLosingZeroOrEmptyValues(): void
    {
        $changes = [
            'name'       => ['from' => '', 'to' => 'Alice'],
            'enabled'    => ['from' => false, 'to' => true],
            'note'       => ['from' => null, 'to' => ''],
            'updated_at' => ['from' => 'old timestamp', 'to' => 'new timestamp'],
        ];
        $result = Mapping::changes($changes, ['from' => 'before', 'to' => 'after'],
            Fields::uppercase()->rename(['name' => 'DISPLAY_NAME']), except: ['updated_at'],
            values: ['enabled' => static fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value]);

        self::assertEquals(['before' => (object) ['DISPLAY_NAME' => '', 'ENABLED' => 0],
            'after'                  => (object) ['DISPLAY_NAME' => 'Alice', 'ENABLED' => 1, 'NOTE' => '']], $result);
        foreach ([['old' => []], ['contact' => []], ['name' => ['from' => 1]]] as $opaque) {
            self::assertSame($opaque, Mapping::changes($opaque, ['from' => 'old', 'to' => 'new'], Fields::uppercase()));
        }
        self::assertEquals(['old' => new \stdClass(), 'new' => new \stdClass()],
            Mapping::changes([], ['from' => 'old', 'to' => 'new'], Fields::uppercase()));
    }
}
