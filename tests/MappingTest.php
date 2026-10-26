<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\Mapping;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MappingTest extends TestCase
{
    public function testFieldsPreserveLiteralNamesNullAndContainerShape(): void
    {
        $source = ['id' => '9223372036854775808', 'status.id' => null, 'private' => 'hidden'];
        $fields = ['id' => 'PERSON_ID', 'status.id' => 'STATUS', 'missing' => 'MISSING'];
        $expected = ['PERSON_ID' => '9223372036854775808', 'STATUS' => null];

        self::assertSame($expected, Mapping::fields($source, $fields));
        self::assertEquals((object) $expected, Mapping::fields((object) $source, $fields));
        self::assertSame([], Mapping::fields([], $fields));
        self::assertSame('{}', json_encode(Mapping::fields(new stdClass(), $fields)));
        self::assertSame('hidden', $source['private']);
    }

    public function testFieldsRejectDuplicateTargetsEvenWhenNoSourceFieldExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::fields([], ['first' => 'NAME', 'second' => 'NAME']);
    }

    public function testEmptyCollectionStillRejectsInvalidFieldConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::many([], ['first' => 'NAME', 'second' => 'NAME']);
    }

    public function testManyMapsListsAndSupportsCallableArrays(): void
    {
        $records = [(object) ['id' => 1], ['id' => 2]];
        self::assertEquals([(object) ['ID' => 1], ['ID' => 2]], Mapping::many($records, ['id' => 'ID']));
        $callback = [new class
        {
            /** @param array<array-key, mixed>|stdClass $record */
            public function label(array|stdClass $record): string
            {
                $id = $record instanceof stdClass ? $record->id : $record['id'];
                if (! is_int($id)) {
                    throw new InvalidArgumentException('Expected a test identifier.');
                }

                return 'item-'.$id;
            }
        }, 'label'];
        self::assertSame(['item-1', 'item-2'], Mapping::many($records, $callback));
        self::assertSame([], Mapping::many([], ['id' => 'ID']));
    }

    #[DataProvider('invalidLists')]
    public function testManyRejectsMalformedListsAndRecords(mixed $items): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::many($items, ['id' => 'ID']);
    }

    /** @return list<array{mixed}> */
    public static function invalidLists(): array
    {
        return [[null], [(object) []], [['first' => ['id' => 1]]], [[1]], [[null]], [[[1, 2]]]];
    }

    public function testItemUsesExplicitPathsAndLeavesCallerEnvelopeUnchanged(): void
    {
        $envelope = ['state' => 'data', 'meta' => (object) ['existing' => true]];
        $before = json_encode($envelope);
        $result = Mapping::item(
            (object) ['data.with.dot' => (object) ['id' => 7]],
            ['id' => 'ID'],
            $envelope,
            ['data.with.dot'],
            ['meta', 'record'],
        );

        self::assertSame('{"state":"data","meta":{"existing":true,"record":{"ID":7}}}', json_encode($result));
        self::assertSame($before, json_encode($envelope));
        self::assertEquals((object) ['data' => ['ID' => 1]], Mapping::item(['id' => 1], ['id' => 'ID'], from: []));
        self::assertEquals((object) ['data' => (object) ['ID' => 2]],
            Mapping::item((object) ['rows' => [(object) ['id' => 2]]], ['id' => 'ID'], from: ['rows', 0]));
    }

    public function testCollectionMapsRowsAndExactTotalsIntoEnvelope(): void
    {
        $json = (object) ['data' => [(object) ['id' => 1], (object) ['id' => 2]], 'meta' => (object) ['total' => '42']];
        $result = Mapping::collection($json, ['id' => 'ID'], ['state' => 'data', 'meta' => []],
            to: ['rows'], totalFrom: ['meta', 'total'], totalTo: ['meta', 'totalCount']);

        self::assertSame('{"state":"data","meta":{"totalCount":42},"rows":[{"ID":1},{"ID":2}]}', json_encode($result));
        self::assertEquals((object) ['data' => []], Mapping::collection((object) ['data' => []], ['id' => 'ID']));
        self::assertSame('{"data":[],"total":0}', json_encode(Mapping::collection(
            (object) ['rows' => [], 'count' => 0], ['id' => 'ID'], from: ['rows'], totalFrom: ['count'], totalTo: ['total'],
        )));
    }

    public function testDestinationPathsFollowPhpKeyIdentityWithoutConflatingLeadingZeros(): void
    {
        $result = Mapping::collection((object) ['data' => [], 'total' => 3], [],
            to: ['data', '01'], totalFrom: ['total'], totalTo: ['data', 1]);
        self::assertSame('{"data":{"01":[],"1":3}}', json_encode($result));

        $this->expectException(InvalidArgumentException::class);
        Mapping::collection((object) ['data' => [], 'total' => 3], [],
            to: ['data', 0], totalFrom: ['total'], totalTo: ['data', '0']);
    }

    public function testTotalsAreOptInAndRequireACompletePairOfPaths(): void
    {
        $source = (object) ['data' => [], 'total' => 0];
        self::assertSame('{"data":[]}', json_encode(Mapping::collection($source, [])));
        foreach ([[['total'], null], [null, ['count']]] as [$from, $to]) {
            try {
                Mapping::collection($source, [], totalFrom: $from, totalTo: $to);
                self::fail('An incomplete total mapping must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('{"data":[],"count":0}', json_encode(Mapping::collection(
            $source, [], totalFrom: ['total'], totalTo: ['count'],
        )));
    }

    #[DataProvider('invalidTotals')]
    public function testCollectionRejectsInvalidTotals(mixed $total): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::collection((object) ['data' => [], 'total' => $total], [], totalFrom: ['total'], totalTo: ['total']);
    }

    /** @return list<array{mixed}> */
    public static function invalidTotals(): array
    {
        return [[null], [false], [1.0], [-1], ['-1'], ['01'], ['+1'], ['-0'], [''], ['9223372036854775808']];
    }

    #[DataProvider('missingSources')]
    public function testItemRejectsMissingOrInvalidSource(mixed $json): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::item($json, []);
    }

    /** @return list<array{mixed}> */
    public static function missingSources(): array
    {
        return [[null], [42], [new stdClass()], [(object) ['data' => null]], [(object) ['data' => true]]];
    }

    public function testNestedSourceCannotTraverseNull(): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::item((object) ['data' => null], [], from: ['data', 'id']);
    }

    public function testCollectionRequiresConfiguredTotalToExist(): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::collection((object) ['data' => []], [], totalFrom: ['total'], totalTo: ['total']);
    }

    public function testCollectionRejectsNonListData(): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::collection((object) ['data' => 'invalid'], []);
    }

    public function testCollectionRequiresANonemptyDataDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::collection((object) ['data' => []], [], to: []);
    }

    public function testCollectionRequiresANonemptyTotalDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('destination paths must not be empty');
        Mapping::collection((object) ['data' => [], 'total' => 1], [], totalFrom: ['total'], totalTo: []);
    }

    public function testCollectionRejectsATotalThatWouldReplaceItsDataContainer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::collection((object) ['data' => [], 'total' => 1], [],
            to: ['meta', 'items'], totalFrom: ['total'], totalTo: ['meta']);
    }

    /**
     * @param array<array-key, mixed> $from
     * @param array<array-key, mixed> $to
     */
    #[DataProvider('invalidDestinations')]
    public function testInvalidPathsAreConfigurationErrors(array $from, array $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::item((object) ['data' => new stdClass()], [], from: $from, to: $to);
    }

    /** @return list<array{array<array-key, mixed>, array<array-key, mixed>}> */
    public static function invalidDestinations(): array
    {
        return [[['key' => 'data'], ['data']], [['data'], []], [[false], ['data']], [['data'], [new stdClass()]]];
    }

    public function testScalarEnvelopeCannotContainDestinationPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::item((object) ['data' => new stdClass()], [], ['meta' => false], to: ['meta', 'item']);
    }

    /**
     * @param list<array-key>|null $source
     * @param list<array-key>|null $target
     */
    #[DataProvider('invalidTotalPaths')]
    public function testCollectionRejectsIncompleteOrOverlappingTotalConfiguration(?array $source, ?array $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::collection((object) ['data' => [], 'total' => 0], [], totalFrom: $source, totalTo: $target);
    }

    /** @return list<array{list<array-key>|null, list<array-key>|null}> */
    public static function invalidTotalPaths(): array
    {
        return [[['total'], null], [null, ['total']], [['total'], ['data']], [['total'], ['data', 'total']], [['total'], []]];
    }

    public function testLegacyQueryTranslatesOnlyConfiguredLiteralFields(): void
    {
        $query = ['r' => 'person/get', 'start' => '20', 'limit' => '10', 'fields' => [], 'without' => [], 'merge_with' => [],
            'filter'  => ['status.id' => ['a', 'b'], 'ACTIVE' => false],
            'sort'    => ['NAME' => 'desc', 'ID' => 'ASC'], 'with' => ['contacts', 'duplicate', 'already-loaded']];

        self::assertSame([
            'filter' => ['status_id' => 'a,b', 'active' => false], 'sort' => '-name,id', 'include' => 'contacts', 'page' => 3, 'per_page' => 10,
        ], Mapping::legacyQuery($query, ['status.id' => 'status_id', 'ACTIVE' => 'active'], ['NAME' => 'name', 'ID' => 'id'],
            ['contacts' => 'contacts', 'duplicate' => 'contacts', 'already-loaded' => '']));
        self::assertSame(['page' => 1, 'per_page' => 20], Mapping::legacyQuery([]));
        self::assertSame(['page' => 1, 'per_page' => 1], Mapping::legacyQuery([], defaultLimit: 1, maxLimit: 1));
        self::assertSame(['page' => 1, 'per_page' => 100], Mapping::legacyQuery(['limit' => 100]));
        self::assertSame(['page' => PHP_INT_MAX, 'per_page' => 1],
            Mapping::legacyQuery(['start' => (string) (PHP_INT_MAX - 1), 'limit' => '1']));
        self::assertFalse(Mapping::legacyQuery(['start' => (string) PHP_INT_MAX, 'limit' => '1']));
        self::assertSame(['page' => 2, 'per_page' => 500], Mapping::legacyQuery(['limit' => 500, 'start' => 500], maxLimit: 1000));
        self::assertSame(['filter' => ['id' => '0,1'], 'page' => 1, 'per_page' => 20],
            Mapping::legacyQuery(['filter' => ['ID' => [0, 1]]], ['ID' => 'id']));
    }

    /** @param array<array-key, mixed> $query */
    #[DataProvider('unsupportedQueries')]
    public function testLegacyQueryKeepsUnsupportedVariantsInYii(array $query): void
    {
        self::assertFalse(Mapping::legacyQuery($query, ['ID' => 'id', 'ALIAS' => 'id'], ['NAME' => 'name'], ['contacts' => 'contacts']));
    }

    /** @return list<array{array<array-key, mixed>}> */
    public static function unsupportedQueries(): array
    {
        return [
            [['id' => 7]], [['unknown'                   => true]], [['fields' => ['ID']]], [['without' => ['ID']]], [['merge_with' => ['contacts']]],
            [['filter' => 'invalid']], [['sort'          => 'invalid']], [['with' => 'invalid']],
            [['filter' => ['UNKNOWN' => 1]]], [['filter' => ['ID' => 1, 'ALIAS' => 2]]], [['filter' => ['ID' => null]]],
            [['filter' => ['ID' => []]]], [['filter'     => ['ID' => ['key' => 1]]]], [['filter' => ['ID' => [[1]]]]],
            [['filter' => ['ID' => [' ']]]], [['filter'  => ['ID' => new stdClass()]]],
            [['sort' => ['UNKNOWN' => 'ASC']]], [['sort' => ['NAME' => false]]], [['sort' => ['NAME' => 'UP']]],
            [['with' => ['unknown']]], [['with'          => [['contacts']]]],
            [['limit' => 'bad']], [['limit'              => 0]], [['limit' => 101]], [['limit' => []]],
            [['start' => 'bad']], [['start'              => -1]], [['start' => 1]], [['start' => []]],
        ];
    }

    /**
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sorts
     * @param array<array-key, mixed> $includes
     */
    #[DataProvider('invalidQueryConfiguration')]
    public function testQueryConfigurationErrorsDoNotSelectYii(array $filters, array $sorts, array $includes, int $default, int $max): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mapping::legacyQuery([], $filters, $sorts, $includes, $default, $max);
    }

    /** @return list<array{array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>, int, int}> */
    public static function invalidQueryConfiguration(): array
    {
        return [[['ID' => true], [], [], 20, 100], [[], ['ID' => false], [], 20, 100], [[], [], ['contacts' => false], 20, 100],
            [[], [], [], 0, 100], [[], [], [], 20, 10]];
    }

    public function testValidationErrorsMergeAliasesAndUnknownFieldsWithoutChangingMessages(): void
    {
        $errors = (object) ['title' => ['Required'], 'product.title' => ['Too short'], 'secret' => ['Invalid'], 'other' => ['Missing']];
        self::assertEquals((object) ['NAME' => ['Required', 'Too short'], '_general' => ['Invalid', 'Missing']],
            Mapping::validationErrors($errors, ['title' => 'NAME', 'product.title' => 'NAME']));
        self::assertEquals((object) ['ALL' => []], Mapping::validationErrors(['unknown' => []], [], 'ALL'));
        self::assertSame('{}', json_encode(Mapping::validationErrors(new stdClass(), [])));
        self::assertSame('{}', json_encode(Mapping::validationErrors([], [])));
    }

    #[DataProvider('invalidValidationErrors')]
    public function testValidationErrorsRejectMalformedMessages(mixed $errors): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        Mapping::validationErrors($errors, []);
    }

    /** @return list<array{mixed}> */
    public static function invalidValidationErrors(): array
    {
        return [[null], [['field' => 'Required']], [['field' => ['named' => 'Required']]], [['field' => [42]]]];
    }
}
