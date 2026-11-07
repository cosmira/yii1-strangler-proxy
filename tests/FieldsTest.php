<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\JsonResource;
use Cosmira\Strangler\Mapping;
use Cosmira\Strangler\Query;
use Cosmira\Strangler\Schema\Fields;
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
        ])->filterAs('display_name', 'query');
        $resource = JsonResource::make()->fields($fields);
        $query = Query::legacy()
            ->filters($fields->only(['display_name']))
            ->sorts($fields->only(['updated_at']))
            ->includes($fields->only(['groups']));

        self::assertSame(['display_name' => 'Анна'], Mapping::fields([
            'PERSON_ID' => 'ignored', 'DISPLAY_NAME' => 'Анна', 'unexpected' => 'ignored',
        ], $fields->only(['display_name'])->writes()));
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

    public function testUppercaseConventionRequiresOnlyExceptions(): void
    {
        $fields = Fields::uppercase()->rename(['id' => 'IDENTITY_STATUS_ID']);
        $resource = JsonResource::make()->fields($fields);
        $response = $resource((object) ['data' => (object) [
            'id'       => 'status-1', 'display_name' => 'Новый', 'note' => null, 'color' => '#123456',
            'editable' => false, 'future_field' => 'new value',
        ]], new Response(), ['action' => 'view']);

        self::assertSame([
            'state' => 'data',
            'data'  => [
                'IDENTITY_STATUS_ID' => 'status-1', 'DISPLAY_NAME' => 'Новый', 'NOTE' => null,
                'COLOR'              => '#123456', 'EDITABLE' => false, 'FUTURE_FIELD' => 'new value',
            ],
        ], json_decode((string) $response->getBody(), true));
        self::assertSame(['display_name' => 'Updated', 'note' => null], Mapping::fields([
            'IDENTITY_STATUS_ID' => 'ignored', 'DISPLAY_NAME' => 'Updated', 'NOTE' => null,
            'EDITABLE'           => 1, 'OWNER' => 2,
        ], $fields->only(['display_name', 'note', 'color'])->writes()));
        self::assertSame(['filter' => ['editable' => 0]], Query::legacy()
            ->filters($fields->only(['editable']))(['filter' => ['EDITABLE' => 0]], ['action' => 'view']));
    }

    public function testAutomaticNamesRetainAliasesAfterSelectingFields(): void
    {
        $fields = Fields::uppercase()->rename(['id' => 'PERSON_ID'])->filterAs('display_name', 'query');

        self::assertSame(['DISPLAY_NAME' => 'query'], $fields->only(['display_name'])->filters());
        self::assertEquals((object) ['PERSON_ID' => 'person-1', 'DISPLAY_NAME' => 'Анна'], Mapping::fields(
            (object) ['id' => 'person-1', 'display_name' => 'Анна'],
            $fields,
        ));
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

    public function testDirectionalAliasesAccumulateWithoutChangingResponseNames(): void
    {
        $fields = Fields::uppercase(['name', 'note'])
            ->filterAs('name', 'query')->filterAs('note', 'description')
            ->sortAs('name', 'title')->sortAs('note', 'description');

        self::assertSame(['NAME' => 'query', 'NOTE' => 'description'], $fields->filters());
        self::assertSame(['NAME' => 'title', 'NOTE' => 'description'], $fields->sorts());
        self::assertSame(['NAME' => 'name', 'NOTE' => 'note'], $fields->writes());
        self::assertSame(['name' => 'NAME', 'note' => 'NOTE'], $fields->names());
    }

    public function testFiniteSchemasRejectUnknownFieldsInEveryDirectionalOverride(): void
    {
        foreach (['rename', 'filterAs', 'sortAs'] as $method) {
            $fields = Fields::uppercase(['name']);

            try {
                $method === 'rename' ? $fields->rename(['unknown' => 'RENAMED']) : $fields->$method('unknown', 'renamed');
                self::fail('An unknown field was accepted by '.$method);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('unknown', $exception->getMessage());
            }
        }
        self::assertSame(['name' => 'TITLE'], Fields::uppercase(['name'])->rename(['name' => 'TITLE'])->names());
    }
}
