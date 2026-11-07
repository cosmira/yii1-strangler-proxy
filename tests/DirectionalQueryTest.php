<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Convention;
use Cosmira\Strangler\Query;
use Cosmira\Strangler\Strangler;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from \Cosmira\Strangler\InlineMapping */
final class DirectionalQueryTest extends TestCase
{
    public function testMalformedIncludeContainersAreRejectedBeforeForwarding(): void
    {
        $mapper = $this->queryMapper(Strangler::proxy('directory')
            ->uppercase('PERSON_ID')
            ->get('get', '/people'));
        $context = ['action' => 'get', 'query' => [], 'payload' => null];

        self::assertFalse($mapper(['with' => 'contacts'], $context));
        self::assertFalse($mapper(['with' => [null]], $context));
    }

    public function testSemanticAdapterReusesAliasesIncludesAndAnImmutableConfiguredQuery(): void
    {
        $builder = Strangler::proxy('directory')->uppercase('PERSON_ID')
            ->query(filters: ['display_name' => 'query'])
            ->query(includes: ['groups' => 'groups_all'])
            ->query(using: static function (array $query, array $context, Query $defaults): array|false {
                if (isset($query['merge_with'])) {
                    return false;
                }
                if (isset($query['legacy_name'])) {
                    $query['filter'] = ['DISPLAY_NAME' => $query['legacy_name']];
                    unset($query['legacy_name']);
                }

                return $defaults($query, $context);
            })
            ->get('get', '/people');
        $first = $this->queryMapper($builder);
        $second = $this->queryMapper($builder->query(filters: ['display_name' => 'search']));
        $query = ['legacy_name' => 'Иван', 'with' => ['groups']];

        self::assertSame([
            'filter' => ['query' => 'Иван'], 'include' => 'groups_all', 'page' => 1, 'per_page' => 20,
        ], $first($query, ['action' => 'get', 'query' => [], 'payload' => null]));
        self::assertSame([
            'filter' => ['search' => 'Иван'], 'include' => 'groups_all', 'page' => 1, 'per_page' => 20,
        ], $second($query, ['action' => 'get', 'query' => [], 'payload' => null]));
        self::assertFalse($first(['merge_with' => ['groups']], ['action' => 'get', 'query' => [], 'payload' => null]));
        self::assertSame([
            'include' => 'unknown', 'page' => 1, 'per_page' => 20,
        ], $first(['with' => ['unknown']], ['action' => 'get', 'query' => [], 'payload' => null]));
    }

    public function testIncludeAliasesCanShareOneTargetOrOmitAnUpstreamRelation(): void
    {
        $mapper = $this->queryMapper(Strangler::proxy('directory')->uppercase('PERSON_ID')
            ->query(includes: [
                'groups' => 'groups_all', 'memberships' => 'groups_all',
                'path'   => '', 'counts' => '',
            ])
            ->get('get', '/people'));

        self::assertSame([
            'include' => 'groups_all,contacts', 'page' => 1, 'per_page' => 20,
        ], $mapper(['with' => ['groups', 'memberships', 'path', 'counts', 'contacts']], [
            'action' => 'get', 'query' => [], 'payload' => null,
        ]));
    }

    public function testQueryExceptionsKeepPayloadResponseAndOrdinaryFieldsIndependent(): void
    {
        $config = Strangler::proxy('directory')->uppercase('PERSON_ID')
            ->query(filters: ['display_name' => 'query'])
            ->query(filters: ['is_visible' => 'visible'])
            ->query(filters: ['is_employee' => 'employee'])
            ->query(sorts: ['display_name' => 'name'])
            ->get('get', '/people')->build()['config'];
        $mappings = $config['mappings'] ?? null;
        self::assertIsArray($mappings);
        $queryMapper = $mappings['query']['get'] ?? null;
        self::assertIsCallable($queryMapper);
        $payloadMapper = $mappings['payload']['get'] ?? null;
        self::assertInstanceOf(Convention::class, $payloadMapper);
        $responseMapper = $mappings['response']['get'] ?? null;
        self::assertIsCallable($responseMapper);
        $context = ['action' => 'get', 'query' => [], 'payload' => null];

        self::assertSame([
            'filter' => ['query' => 'Иван', 'visible' => 1, 'employee' => 0, 'source' => 'ad'],
            'sort'   => '-name,create_date', 'page' => 1, 'per_page' => 20,
        ], $queryMapper([
            'filter' => ['DISPLAY_NAME' => 'Иван', 'IS_VISIBLE' => 1, 'IS_EMPLOYEE' => 0, 'SOURCE' => 'ad'],
            'sort'   => ['DISPLAY_NAME' => 'DESC', 'CREATE_DATE' => 'ASC'],
        ], $context));
        self::assertSame(['display_name' => 'Иван', 'is_visible' => 1, 'is_employee' => 0],
            $payloadMapper->payload([
                'PERSON_ID' => 'p', 'DISPLAY_NAME' => 'Иван', 'IS_VISIBLE' => 1, 'IS_EMPLOYEE' => 0,
            ]));
        $response = $responseMapper(
            json_decode('{"data":[{"id":"p","display_name":"Иван","is_visible":1,"is_employee":0}],"meta":{"total":1}}'),
            new Response(), $context,
        );
        self::assertSame([
            'state' => 'data',
            'data'  => [['PERSON_ID' => 'p', 'DISPLAY_NAME' => 'Иван', 'IS_VISIBLE' => 1, 'IS_EMPLOYEE' => 0]],
            'meta'  => ['totalCount' => 1],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testBuiltConfigurationsRetainTheirDirectionalSnapshots(): void
    {
        $builder = Strangler::proxy('directory')->uppercase('PERSON_ID')
            ->request(fields: ['TITLE' => 'display_name'])
            ->query(filters: ['display_name' => 'query'])->get('get', '/people');
        $before = $this->queryMapper($builder);
        $builder->query(filters: ['display_name' => 'search'])->query(filters: ['is_visible' => 'visible'])
            ->query(sorts: ['display_name' => 'name']);
        $after = $this->queryMapper($builder);
        $query = ['filter' => ['TITLE' => 'Иван', 'IS_VISIBLE' => 1], 'sort' => ['TITLE' => 'ASC']];

        self::assertSame([
            'filter' => ['query' => 'Иван', 'is_visible' => 1], 'sort' => 'display_name',
            'page'   => 1, 'per_page' => 20,
        ], $before($query, ['action' => 'get', 'query' => [], 'payload' => null]));
        self::assertSame([
            'filter' => ['search' => 'Иван', 'visible' => 1], 'sort' => 'name',
            'page'   => 1, 'per_page' => 20,
        ], $after($query, ['action' => 'get', 'query' => [], 'payload' => null]));
    }

    /** @return callable(array<array-key, mixed>, Context): (array<array-key, mixed>|false) */
    private function queryMapper(Strangler $builder): callable
    {
        $config = $builder->build()['config'];
        $mappings = $config['mappings'] ?? null;
        self::assertIsArray($mappings);
        $mapper = $mappings['query']['get'] ?? null;
        self::assertIsCallable($mapper);

        return $mapper;
    }

    #[DataProvider('invalidAliases')]
    public function testDirectionalAliasesRejectEmptyNames(string $method, string $field, string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('directory')->query(...[$method => [$field => $name]]);
    }

    /** @return list<array{string, string, string}> */
    public static function invalidAliases(): array
    {
        return [['filters', '', 'query'], ['filters', 'name', ''], ['sorts', '', 'name'], ['sorts', 'name', '']];
    }

    public function testDirectionalAliasesRequireTheNamingConvention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('directory')->query(filters: ['display_name' => 'query'])->get('get', '/people')->build();
    }
}
