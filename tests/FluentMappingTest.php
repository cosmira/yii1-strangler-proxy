<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\InvalidUpstreamResponse;
use Cosmira\Strangler\JsonResource;
use Cosmira\Strangler\Query;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;
use UnexpectedValueException;

final class FluentMappingTest extends TestCase
{
    public function testQueryMapsFiltersSortingIncludesAndPagination(): void
    {
        $mapper = Query::legacy()
            ->filters(['name' => 'title'])
            ->sorts(['name' => 'title'])
            ->includes(['owner' => 'author'])
            ->pagination(default: 5, max: 10);
        $query = [
            'id'     => 3,
            '_'      => 4,
            'filter' => ['name' => 'desk'],
            'sort'   => ['name' => 'DESC'],
            'with'   => ['owner'],
            'start'  => 5,
        ];

        $mapped = $mapper($query, ['action' => 'get']);

        self::assertSame([
            'filter'   => ['title' => 'desk'],
            'sort'     => '-title',
            'include'  => 'author',
            'page'     => 2,
            'per_page' => 5,
        ], $mapped);
    }

    public function testUnsupportedQueriesKeepLegacyExecution(): void
    {
        $mapper = Query::legacy()->pagination(default: 5, max: 10);

        self::assertFalse($mapper(['unknown' => 1], ['action' => 'get']));
        self::assertFalse($mapper(['limit' => 11], ['action' => 'get']));
        self::assertFalse($mapper(['fields' => ['name']], ['action' => 'show']));
    }

    public function testNonListActionsDiscardPaginationBeforeValidation(): void
    {
        $mapper = Query::legacy();
        $mapped = $mapper(['start' => 'bad', 'limit' => 'bad'], ['action' => 'show']);

        self::assertSame([], $mapped);
    }

    public function testQueryActionAndIgnoredFieldOverridesReplaceDefaults(): void
    {
        $mapper = Query::legacy()
            ->pagination(default: 5, max: 10)
            ->lists(['search'])
            ->ignore(['token']);

        $mapped = $mapper(['token' => 'x'], ['action' => 'search']);

        self::assertSame(['page' => 1, 'per_page' => 5], $mapped);
        self::assertFalse($mapper(['id' => 3], ['action' => 'get']));
        self::assertSame([], $mapper([], ['action' => 'get']));
    }

    public function testPaginationWithoutArgumentsUsesDocumentedDefaultAndMaximum(): void
    {
        $mapper = Query::legacy()->pagination();

        self::assertSame(['page' => 1, 'per_page' => 20], $mapper([], ['action' => 'get']));
        self::assertSame(['page' => 1, 'per_page' => 100], $mapper(['limit' => 100], ['action' => 'get']));
        self::assertFalse($mapper(['limit' => 101], ['action' => 'get']));
    }

    /**
     * @param callable(Query): Query        $configure
     * @param array<array-key, mixed>       $input
     * @param array<array-key, mixed>|false $expected
     */
    #[DataProvider('queryForks')]
    public function testQueryTemplatesCanBeReusedWithoutChangingEarlierContracts(
        callable $configure,
        array $input,
        array|false $expected,
    ): void {
        $template = Query::legacy()
            ->filters(['name' => 'title'])
            ->sorts(['name' => 'title'])
            ->includes(['owner' => 'author']);
        $before = $template($input, ['action' => 'get']);

        $variant = $configure($template);

        self::assertSame($expected, $variant($input, ['action' => 'get']));
        self::assertSame($before, $template($input, ['action' => 'get']));
    }

    /**
     * @return list<array{
     *     callable(Query): Query, array<array-key, mixed>, array<array-key, mixed>|false
     * }>
     */
    public static function queryForks(): array
    {
        return [
            [static fn (Query $query): Query => $query->filters([]), ['filter' => ['name' => 'desk']], false],
            [static fn (Query $query): Query => $query->sorts([]), ['sort' => ['name' => 'ASC']], false],
            [static fn (Query $query): Query => $query->includes([]), ['with' => ['owner']], false],
            [static fn (Query $query): Query => $query->pagination(5), [], ['page' => 1, 'per_page' => 5]],
            [static fn (Query $query): Query => $query->lists([]), [], []],
            [static fn (Query $query): Query => $query->ignore([]), ['id' => 1], false],
        ];
    }

    public function testPayloadUsesOnlyWritableFieldsAndPreservesNull(): void
    {
        $resource = JsonResource::make()
            ->fields(['id' => 'key', 'name' => 'title'])
            ->writable(['name']);

        $payload = $resource->payload(['key' => 1, 'title' => null, 'extra' => 2]);

        self::assertSame(['name' => null], $payload);
    }

    public function testResourceMapsItemsAndNormalizesTheirStatus(): void
    {
        $resource = JsonResource::make()->fields(['id' => 'key']);
        $json = (object) ['data' => (object) ['id' => 1]];

        $response = $resource($json, new Response(201), ['action' => 'create']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"state":"data","data":{"key":1}}', (string) $response->getBody());
    }

    public function testResourceCanPreserveTheUpstreamStatus(): void
    {
        $resource = JsonResource::make()->status(null);
        $json = (object) ['data' => (object) []];

        $response = $resource($json, new Response(201), ['action' => 'create']);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testResourceMapsCollectionsAndTotals(): void
    {
        $resource = JsonResource::make()->fields(['id' => 'key']);
        $json = json_decode('{"data":[{"id":1}],"meta":{"total":"7"}}');

        $response = $resource($json, new Response(), ['action' => 'get']);

        self::assertSame(
            '{"state":"data","data":[{"key":1}],"meta":{"totalCount":7}}',
            (string) $response->getBody(),
        );
    }

    public function testResourceAllowsCustomRecordsCollectionActionsEnvelopeTotalsAndStatus(): void
    {
        $resource = JsonResource::make()
            ->recordUsing(static fn (array|stdClass $record): array => ['custom' => true])
            ->collections(['search'])
            ->envelope(['ok' => true])
            ->totals(['count'], ['total'])
            ->status(202);
        $json = json_decode('{"data":[{}],"count":0}');

        $response = $resource($json, new Response(), ['action' => 'search']);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('{"ok":true,"data":[{"custom":true}],"total":0}', (string) $response->getBody());
    }

    public function testResourceRejectsAmbiguousFieldNamesBeforeAnUpstreamRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonResource::make()->fields(['id' => 'key', 'name' => 'key']);
    }

    /** @param callable(JsonResource): JsonResource $configure */
    #[DataProvider('resourceForks')]
    public function testResourceTemplatesCanBeReusedWithoutChangingEarlierResponses(
        callable $configure,
        string $body,
        string $action,
        string $expectedBody,
        int $expectedStatus,
    ): void {
        $template = JsonResource::make()->fields(['id' => 'key']);
        $json = json_decode($body);
        $before = $template($json, new Response(), ['action' => $action]);

        $variant = $configure($template);
        $response = $variant($json, new Response(), ['action' => $action]);
        $after = $template($json, new Response(), ['action' => $action]);

        self::assertSame($expectedBody, (string) $response->getBody());
        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame((string) $before->getBody(), (string) $after->getBody());
        self::assertSame($before->getStatusCode(), $after->getStatusCode());
    }

    /** @return list<array{callable(JsonResource): JsonResource, string, string, string, int}> */
    public static function resourceForks(): array
    {
        $item = '{"data":{"id":1}}';
        $list = '{"data":[],"meta":{"total":2}}';

        return [
            [static fn (JsonResource $resource): JsonResource => $resource->fields(['id' => 'identifier']),
                $item, 'view', '{"state":"data","data":{"identifier":1}}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->recordUsing(static fn (): array => ['custom' => true]),
                $item, 'view', '{"state":"data","data":{"custom":true}}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->envelope(['ok' => true]),
                $item, 'view', '{"ok":true,"data":{"key":1}}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->totals(['meta', 'total'], ['count']),
                $list, 'get', '{"state":"data","data":[],"count":2}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->collections([]),
                $list, 'get', '{"state":"data","data":[]}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->empty([]),
                $item, 'delete', '{"state":"data","data":{"key":1}}', 200],
            [static fn (JsonResource $resource): JsonResource => $resource->status(202),
                $item, 'view', '{"state":"data","data":{"key":1}}', 202],
        ];
    }

    public function testWritePayloadTemplatesCanBeRestrictedIndependently(): void
    {
        $template = JsonResource::make()->fields(['id' => 'key', 'name' => 'title'])->writable(['id']);
        $payload = ['key' => 1, 'title' => 'desk'];

        $variant = $template->writable(['name']);

        self::assertSame(['name' => 'desk'], $variant->payload($payload));
        self::assertSame(['id' => 1], $template->payload($payload));
    }

    public function testEmptyResponsesUse200WhenNonemptyResponsesPreserveTheirStatus(): void
    {
        $resource = JsonResource::make()->status(null);

        $response = $resource(null, new Response(204), ['action' => 'view']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $response->getBody());
    }

    public function testDeleteActionsProduceOnlyTheEnvelope(): void
    {
        $resource = JsonResource::make();

        $response = $resource(null, new Response(), ['action' => 'delete']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $response->getBody());
    }

    public function testEmptyActionOverridesAnd204SkipRecordMapping(): void
    {
        $resource = JsonResource::make()->empty(['remove']);

        $removed = $resource(null, new Response(), ['action' => 'remove']);
        $noContent = $resource->empty([])->status(201)(null, new Response(204), ['action' => 'show']);
        $delete = $resource->empty([])(json_decode('{"data":{}}'), new Response(), ['action' => 'delete']);

        self::assertSame(200, $removed->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $removed->getBody());
        self::assertSame(201, $noContent->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $noContent->getBody());
        self::assertSame('{"state":"data","data":{}}', (string) $delete->getBody());
    }

    public function testInlineDeleteMappingStillRejectsMalformedJsonBeforeResourceMapping(): void
    {
        $mapping = new InlineMapping(
            ['response' => ['*' => JsonResource::make()]],
            ['action' => 'delete', 'query' => [], 'payload' => null],
        );

        $malformed = $mapping->response(new Response(200, [], '{broken'));
        $valid = $mapping->response(new Response(200, [], '{"anything":true}'));
        $empty = $mapping->response(new Response(204));

        self::assertSame(502, $malformed->getStatusCode());
        self::assertSame(
            '{"state":"error","error":"invalid_upstream_response"}',
            (string) $malformed->getBody(),
        );
        self::assertSame(200, $valid->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $valid->getBody());
        self::assertSame(200, $empty->getStatusCode());
        self::assertSame('{"state":"data"}', (string) $empty->getBody());
    }

    #[DataProvider('malformedResources')]
    public function testMalformedResourcesAreContained(mixed $json, string $action): void
    {
        $this->expectException(InvalidUpstreamResponse::class);
        JsonResource::make()($json, new Response(), ['action' => $action]);
    }

    /** @return list<array{mixed, string}> */
    public static function malformedResources(): array
    {
        return [
            [null, 'show'],
            [(object) [], 'show'],
            [(object) ['data' => null], 'show'],
            [(object) ['data' => [1]], 'get'],
            [(object) ['data' => []], 'get'],
            [json_decode('{"data":[],"meta":{"total":-1}}'), 'get'],
        ];
    }

    public function testResourceCallbackReceivesContextAndRetainsWritableFields(): void
    {
        $resource = JsonResource::make()
            ->fields(['name' => 'title'])
            ->writable(['name'])
            ->recordUsing(static fn (array|stdClass $record, array $context): array => [
                'action' => $context['action'],
            ]);

        $payload = $resource->payload(['title' => null]);
        $response = $resource(json_decode('{"data":{}}'), new Response(), ['action' => 'view']);

        self::assertSame(['name' => null], $payload);
        self::assertSame('{"state":"data","data":{"action":"view"}}', (string) $response->getBody());
    }

    public function testInlineMappingPreservesCallbackTypeErrors(): void
    {
        $resource = JsonResource::make()->recordUsing(static fn (): never => throw new TypeError('bad record'));

        $mapping = new InlineMapping(
            ['response' => ['*' => $resource]],
            ['action' => 'view', 'query' => [], 'payload' => null],
        );

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('bad record');
        $mapping->response(new Response(200, [], '{"data":{}}'));
    }

    public function testInlineMappingPreservesCallbackProgrammingFailures(): void
    {
        $resource = JsonResource::make()->recordUsing(static fn (): never => throw new UnexpectedValueException('bad'));

        $mapping = new InlineMapping(
            ['response' => ['*' => $resource]],
            ['action' => 'show', 'query' => [], 'payload' => null],
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('bad');
        $mapping->response(new Response(200, [], '{"data":{}}'));
    }
}
