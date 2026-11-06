<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Fields;
use Cosmira\Strangler\JsonResource;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Query;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FieldsTest extends TestCase
{
    public function testOneSchemaMapsResponsesWritesFiltersSortingAndIncludes(): void
    {
        $fields = Fields::uppercase([
            'id' => 'PERSON_ID',
            'display_name',
            'updated_at' => 'CHANGE_DATE',
            'groups'     => 'parents',
        ])->filterAs('display_name', 'query')->writeAs('display_name', 'name');
        $resource = JsonResource::make()->fields($fields)->writable(['display_name']);
        $query = Query::legacy()
            ->filters($fields->only(['display_name']))
            ->sorts($fields->only(['updated_at']))
            ->includes($fields->only(['groups']));

        self::assertSame(['name' => 'Анна'], $resource->payload([
            'PERSON_ID' => 'ignored', 'DISPLAY_NAME' => 'Анна', 'unexpected' => 'ignored',
        ]));
        self::assertSame([
            'filter'   => ['query' => 'Анна'],
            'sort'     => '-updated_at',
            'include'  => 'groups',
            'page'     => 1,
            'per_page' => 20,
        ], $query([
            'filter' => ['DISPLAY_NAME' => 'Анна'],
            'sort'   => ['CHANGE_DATE' => 'DESC'],
            'with'   => ['parents'],
        ], ['action' => 'get']));
        $response = $resource((object) ['data' => (object) [
            'id' => 'person-1', 'display_name' => null, 'updated_at' => 'date', 'unknown' => 'ignored',
        ]], new Response(), ['action' => 'view']);
        self::assertSame([
            'state' => 'data',
            'data'  => ['PERSON_ID' => 'person-1', 'DISPLAY_NAME' => null, 'CHANGE_DATE' => 'date'],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testSelectionsDoNotChangeTheOriginalSchema(): void
    {
        $fields = Fields::uppercase(['id' => 'PERSON_ID', 'display_name']);
        self::assertSame(['display_name' => 'DISPLAY_NAME'], $fields->only(['display_name'])->names());
        self::assertSame(['PERSON_ID' => 1, 'DISPLAY_NAME' => 'Анна'], Mapping::fields([
            'id' => 1, 'display_name' => 'Анна',
        ], $fields));
    }

    public function testSortAliasesDoNotChangeResponseNames(): void
    {
        $fields = Fields::uppercase(['created_at' => 'CREATE_DATE'])->sortAs('created_at', 'create_date');
        self::assertSame(['CREATE_DATE' => 'date'], Mapping::fields(['created_at' => 'date'], $fields));
        self::assertSame(['sort' => 'create_date'], Query::legacy()->sorts($fields)([
            'sort' => ['CREATE_DATE' => 'ASC'],
        ], ['action' => 'view']));
    }

    public function testUnknownSelectionsFailAtConfigurationTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fields::uppercase(['name'])->only(['missing']);
    }

    public function testAmbiguousInverseNamesFailAtConfigurationTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Query::legacy()->filters(Fields::uppercase(['name' => 'NAME', 'title' => 'NAME']));
    }
}
