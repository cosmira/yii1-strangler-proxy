<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\Record;
use Cosmira\Strangler\Schema\Fields;
use PHPUnit\Framework\TestCase;

final class RecordTest extends TestCase
{
    public function testRelationNamesCannotOverwriteAttributesEvenWhenTheirValueIsNull(): void
    {
        $record = Record::uppercase()->one('owner', Record::uppercase(), 'NAME');
        $this->expectException(InvalidUpstreamResponse::class);

        $record(['name' => null, 'owner' => ['name' => 'Alice']]);
    }

    public function testTwoPresentRelationsCannotOverwriteEachOther(): void
    {
        $record = Record::uppercase()
            ->one('owner', Record::uppercase(), 'person')
            ->one('manager', Record::uppercase(), 'person');
        $this->expectException(InvalidUpstreamResponse::class);

        $record(['owner' => null, 'manager' => ['name' => 'Alice']]);
    }

    public function testAbsentRelationsDoNotConflictWithPresentAttributesOrRelations(): void
    {
        $record = Record::uppercase()
            ->one('owner', Record::uppercase(), 'NAME')
            ->one('manager', Record::uppercase(), 'NAME');

        self::assertSame(['NAME' => 'Desk'], $record(['name' => 'Desk']));
        self::assertSame(['NAME' => ['NAME' => 'Alice']], $record(['manager' => ['name' => 'Alice']]));
    }

    public function testNormalizersComposeInOrderWithoutChangingTheirOriginalConfiguration(): void
    {
        $base = Record::uppercase()->attributesUsing(static function (array $data): array {
            self::assertIsString($data['name']);
            $data['name'] = trim($data['name']);

            return $data;
        });
        $fork = $base->attributesUsing(static function (array $data): array {
            self::assertIsString($data['name']);
            $data['name'] .= '!';

            return $data;
        });

        self::assertSame(['NAME' => 'Original!'], $fork(['name' => ' Original ']));
        self::assertSame(['NAME' => 'Original'], $base(['name' => ' Original ']));
    }

    public function testDerivedFieldsComposeAfterNamingWithoutChangingTheirBase(): void
    {
        $base = Record::uppercase()->afterUsing(static fn (array $mapped, array $raw): array => $mapped + [
            'label' => $raw['name'],
        ]);
        $fork = $base->afterUsing(static function (array $mapped): array {
            self::assertIsString($mapped['label']);
            $mapped['label'] = strtoupper($mapped['label']);

            return $mapped;
        });

        self::assertSame(['NAME' => 'Original', 'label' => 'ORIGINAL'], $fork(['name' => 'Original']));
        self::assertSame(['NAME' => 'Original', 'label' => 'Original'], $base(['name' => 'Original']));
    }

    public function testNamesCallbacksAndSourceTypesArePreserved(): void
    {
        $record = Record::uppercase()->identifier('ITEM_ID')
            ->rename(['name' => 'TITLE'])->except(['private'])
            ->attributesUsing(static function (array $data): array {
                $data['enabled'] = $data['enabled'] === true ? 1 : 0;

                return $data;
            })
            ->afterUsing(static fn (array $mapped, array $raw): array => $mapped + ['original' => $raw['enabled']]);
        $input = ['id' => 0, 'name' => null, 'private' => 'secret', 'enabled' => false];
        $expected = ['ITEM_ID' => 0, 'TITLE' => null, 'ENABLED' => 0, 'original' => false];

        self::assertSame($expected, $record($input));
        self::assertEquals((object) $expected, $record((object) $input));
        self::assertSame('ITEM_ID', $record->schema()->legacyName('id'));
    }

    public function testRelationsUseNativeNamesAndNestedProjectionWithoutChangingContext(): void
    {
        $contexts = [];
        $child = Record::fields(Fields::uppercase())->identifier('CHILD_ID')
            ->optional(['note'])
            ->projectionUsing(static function (array $context) use (&$contexts): array {
                $contexts[] = $context;

                return ['NOTE' => []];
            });
        $record = Record::uppercase()->identifier('ITEM_ID')
            ->many('children', $child, 'descendants')->one('owner', $child)
            ->projectionUsing(static fn (array $context): array => $context['selection'] === [] ? [] : ['descendants' => [], 'owner' => []]);
        $context = ['selection' => ['descendants' => [], 'owner' => []]];
        $mapped = $record((object) [
            'id'       => 'a',
            'children' => [(object) ['id' => 'b', 'note' => 'hidden'], ['id' => 'c']],
            'owner'    => null,
        ], $context);

        self::assertEquals((object) [
            'ITEM_ID' => 'a', 'descendants' => [(object) ['CHILD_ID' => 'b'], ['CHILD_ID' => 'c']], 'owner' => null,
        ], $mapped);
        self::assertSame([], $contexts);
        self::assertSame(['selection' => ['descendants' => [], 'owner' => []]], $context);
        self::assertSame(['ITEM_ID' => 'a'], $record(['id' => 'a', 'children' => []], ['selection' => []]));
    }

    public function testAttributesCanOmitAnUnavailableRelation(): void
    {
        $record = Record::uppercase()->one('owner', Record::uppercase())
            ->attributesUsing(static fn (array $data): array => array_diff_key($data, ['owner' => true]));

        self::assertSame(['ID' => 'a'], $record(['id' => 'a', 'owner' => null]));
    }

    public function testLazyRelationsAreBuiltOnlyWhenPresentAndOncePerCollection(): void
    {
        $calls = 0;
        $record = Record::uppercase()->many('children', static function () use (&$calls): Record {
            $calls++;

            return Record::uppercase()->identifier('CHILD_ID');
        });

        self::assertSame(['ID' => 'a'], $record(['id' => 'a']));
        self::assertSame(0, $calls);
        self::assertSame(['children' => [['CHILD_ID' => 'b'], ['CHILD_ID' => 'c']]], $record([
            'children' => [['id' => 'b'], ['id' => 'c']],
        ]));
        self::assertSame(1, $calls);
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        foreach ([[], ['id' => null], ['id' => true], ['id' => []]] as $input) {
            try {
                Record::uppercase()->identifier('ITEM_ID')($input);
                self::fail('Invalid identifier was accepted.');
            } catch (InvalidUpstreamResponse $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testInvalidRelatedRecordsAndCollectionsAreRejected(): void
    {
        $child = Record::uppercase()->identifier('ITEM_ID');
        $record = Record::uppercase()->many('children', $child)->one('owner', $child);
        foreach ([
            ['children' => (object) []], ['children' => ['key' => ['id' => 'a']]],
            ['children' => [false]], ['children'     => [['id' => true]]],
            ['owner' => 'invalid'], ['owner'         => ['a', 'b']],
        ] as $input) {
            try {
                $record($input);
                self::fail('Invalid related value was accepted.');
            } catch (InvalidUpstreamResponse $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testConfigurationsCanBeForkedWithoutChangingTheOriginalGraph(): void
    {
        $child = Record::uppercase()->identifier('CHILD_ID');
        $base = Record::uppercase()->many('children', $child)->optional(['note'])
            ->projectionUsing(static fn (): array => ['children' => [], 'NOTE' => []]);
        $configurations = [
            fn () => $base->rename(['id' => 'OTHER_ID']),
            fn () => $base->except(['name']),
            fn () => $base->optional(['name']),
            fn () => $base->many('other', $child),
            fn () => $base->projectionUsing(static fn (): array => []),
            fn () => $base->attributesUsing(static fn (array $data): array => array_diff_key($data, ['name' => true])),
            fn () => $base->afterUsing(static fn (array $data): array => $data + ['computed' => true]),
        ];

        foreach ($configurations as $configure) {
            $configure();
            self::assertSame([
                'ID'       => 'a', 'NAME' => 'Original', 'NOTE' => 'Visible', 'OTHER' => [],
                'children' => [['CHILD_ID' => 'b']],
            ], $base(['id' => 'a', 'name' => 'Original', 'note' => 'Visible', 'other' => [], 'children' => [['id' => 'b']]]));
        }
    }

    public function testSkippingOneRelationDoesNotSkipLaterRequestedRelations(): void
    {
        $record = Record::uppercase()->many('hidden', Record::uppercase())
            ->many('visible', Record::uppercase())
            ->projectionUsing(static fn (): array => ['visible' => []]);

        self::assertSame(['visible' => [['ID' => 'b']]], $record(['hidden' => [], 'visible' => [['id' => 'b']]]));
    }

    public function testOptionalAttributesRemainVisibleWithoutAProjection(): void
    {
        self::assertSame(['NOTE' => 'Visible'], Record::uppercase()->optional(['note'])(['note' => 'Visible']));
    }

    public function testEmptyRecordsAreAcceptedButNonemptyListsAreRejected(): void
    {
        self::assertSame([], Record::uppercase()([]));
        $this->expectException(InvalidUpstreamResponse::class);

        Record::uppercase()(['not an object']);
    }

    public function testMalformedRelationProjectionIsRejected(): void
    {
        $record = Record::uppercase()
            ->one('owner', Record::uppercase())
            ->projectionUsing(static fn (): array => ['owner' => 'invalid']);
        $this->expectException(InvalidUpstreamResponse::class);

        $record(['owner' => ['id' => 'a']]);
    }

    public function testNestedProjectionsUseRelationNamesRatherThanNumericListKeys(): void
    {
        $record = Record::uppercase()->one('owner', Record::uppercase())
            ->projectionUsing(static fn (): array => ['owner' => ['not normalized']]);
        $this->expectException(InvalidUpstreamResponse::class);

        $record(['owner' => ['id' => 'a']]);
    }

    public function testNestedProjectionRetainsTheRequestedOptionalAttribute(): void
    {
        $record = Record::uppercase()
            ->one('owner', Record::uppercase()->optional(['note', 'private']))
            ->projectionUsing(static fn (): array => ['owner' => ['NOTE' => []]]);

        self::assertSame(['owner' => ['NOTE' => 'Visible']], $record([
            'owner' => ['note' => 'Visible', 'private' => 'Hidden'],
        ]));
    }

    public function testAmbiguousUpstreamAttributesAreRejectedAsInvalidResponseData(): void
    {
        $this->expectException(InvalidUpstreamResponse::class);

        Record::uppercase()->rename(['first' => 'VALUE'])(['first' => 'one', 'value' => 'two']);
    }
}
