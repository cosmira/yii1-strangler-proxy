<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Response\ErrorResponse;
use Cosmira\Strangler\Response\Json;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Response\Record;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\ApplicationEnded;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Yii;

/** @phpstan-import-type Config from Strangler */
final class ConventionTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];
    private int $status = 200;

    protected function setUp(): void
    {
        if (! defined('YII_DEBUG')) {
            define('YII_DEBUG', false);
        }
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_HOST'] = 'legacy.test';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => ['request' => ['class' => Request::class], 'user' => ['class' => User::class]],
            'params'     => ['strangler' => ['base_uri' => 'https://backend.test', 'features' => ['catalog' => true]]],
        ]);
    }

    public function testAlternativeInputAliasesAreAllowedButAnAmbiguousPayloadIsNot(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(fields: ['NAME' => 'name', 'TITLE' => 'name'])
            ->post('create', '/items')->build()['config'];
        $result = $this->forward($config, 'create', '{"NAME":"alice","TITLE":"bob"}',
            new Response(201, [], '{"data":{"id":"a"}}'));
        self::assertFalse($result);
        self::assertSame([], $this->history);

        $this->forward($config, 'create', '{"NAME":"alice"}',
            new Response(201, [], '{"data":{"id":"a"}}'));
        self::assertSame(['name' => 'alice'], $this->decoded((string) $this->lastRequest()->getBody()));
    }

    public function testResponseAliasesCannotOverwriteTheIdentifier(): void
    {
        $builder = Strangler::proxy('catalog')
            ->uppercase('ITEM_ID')
            ->response(fields: ['name' => 'ITEM_ID'])
            ->get('view', '/items/{id}');

        $this->expectException(\InvalidArgumentException::class);

        $builder->build();
    }

    public function testUnknownFiltersAndSortsReachTheBackendAndItsRejectionDoesNotReplayYii(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->get('get', '/people')->build()['config'];
        $_GET = [
            'filter' => ['PERSON_ID' => 'person-1', 'FUTURE_FIELD' => ''],
            'sort'   => ['SN' => 'DESC'], 'with' => ['undeclared_relation'], 'limit' => 250,
        ];
        $result = $this->forward($filter, 'get', '', new Response(422, [],
            '{"message":"Unsupported filter","errors":{"person_id":["Unsupported filter"]}}'));

        self::assertNotFalse($result);
        self::assertCount(1, $this->history);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['person_id' => 'person-1', 'future_field' => ''], $query['filter']);
        self::assertSame('-sn', $query['sort']);
        self::assertSame('undeclared_relation', $query['include']);
        self::assertSame('250', $query['per_page']);
        self::assertSame(422, $this->status);
        self::assertSame('Unsupported filter', $this->decoded($result)['message']);
    }

    public function testOrdinaryNamesNeedNoFieldPermissionOrSortLists(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->get('get', '/items')->build()['config'];
        $_GET = [
            'filter' => ['EDITABLE' => 0, 'SOURCE' => ['tm', 'ad']],
            'sort'   => ['DISPLAY_NAME' => 'DESC', 'CREATE_DATE' => 'ASC'],
            'with'   => ['contacts'], 'start' => 20, 'limit' => 10,
        ];
        $result = $this->forward($filter, 'get', '', new Response(200, [],
            '{"data":[{"id":"a","display_name":"Name","new_field":false}],"meta":{"total":30}}'));

        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['editable' => '0', 'source' => 'tm,ad'], $query['filter']);
        self::assertSame('-display_name,create_date', $query['sort']);
        self::assertSame('contacts', $query['include']);
        self::assertSame('3', $query['page']);
        self::assertSame(['state' => 'data', 'data' => [[
            'ITEM_ID' => 'a', 'DISPLAY_NAME' => 'Name', 'NEW_FIELD' => false,
        ]], 'meta' => ['totalCount' => 30]], $this->decoded($result));
    }

    public function testResponseAliasesNeverRenameTheWriteContract(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->response(fields: ['given_name' => 'GIVENNAME', 'surname' => 'SN'])
            ->post('create', '/people')->build()['config'];
        $result = $this->forward($filter, 'create', '{"GIVENNAME":"Иван","SN":"Иванов","DISABLED":false}',
            new Response(201, [], '{"data":{"id":"p","given_name":"Иван","surname":"Иванов"}}'));

        self::assertSame(['givenname' => 'Иван', 'sn' => 'Иванов', 'disabled' => false],
            json_decode((string) $this->history[0]['request']->getBody(), true));
        self::assertSame(['state' => 'data', 'data' => ['PERSON_ID' => 'p', 'GIVENNAME' => 'Иван', 'SN' => 'Иванов']], $this->decoded($result));
    }

    public function testBodyGroupSelectsTheRouteWithoutLeakingIntoQueryOrBody(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/groups/{group}/people', fromBody: ['group' => 'GROUPS.*.GROUP_ID'])
            ->build()['config'];
        $this->forward($filter, 'create', '{"GROUPS":[{"GROUP_ID":"a/b"}],"SN":"Name"}',
            new Response(201, [], '{"data":{"id":"p"}}'));

        self::assertSame('/groups/a%2Fb/people', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('', $this->history[0]['request']->getUri()->getQuery());
        self::assertSame('{"sn":"Name"}', (string) $this->history[0]['request']->getBody());
    }

    public function testInvalidOrAmbiguousBindingsRetainNativeExecutionWithoutHttp(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/groups/{group}/people', fromBody: [
                'group' => ['GROUPS.*.GROUP_ID', 'GROUPS.*.PARENT_GROUP_ID'],
            ])->build()['config'];
        foreach ([
            '{}', '{"GROUPS":[]}', '{"GROUPS":[{"GROUP_ID":"a"},{"GROUP_ID":"b"}]}',
            '{"GROUPS":[{"GROUP_ID":null,"PARENT_GROUP_ID":"b"}]}',
            '{"GROUPS":[{"GROUP_ID":"a","PARENT_GROUP_ID":"b"}]}',
            '{"GROUPS":[{"GROUP_ID":true}]}', '{"GROUPS":[{"GROUP_ID":1.5}]}',
            '{"GROUPS":[{"GROUP_ID":""}]}', '{"GROUPS":[{"GROUP_ID":"."}]}',
            '{"GROUPS":[{"GROUP_ID":".."}]}', '{"GROUPS":["not-a-group"]}',
        ] as $body) {
            self::assertFalse($this->forward($filter, 'create', $body, new Response()));
        }
        self::assertSame([], $this->history);
    }

    public function testConsumptionPreservesSiblingsAndTheOriginalCallbackSnapshot(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->mapPayload(static function (array $body, array $context): array {
                self::assertIsArray($body['GROUPS']);
                self::assertInstanceOf(\stdClass::class, $body['GROUPS'][0]);
                self::assertIsArray($context['payload']);
                self::assertIsArray($context['payload']['GROUPS']);
                self::assertInstanceOf(\stdClass::class, $context['payload']['GROUPS'][0]);
                self::assertSame('kept', $body['GROUPS'][0]->ROLE);
                self::assertFalse(property_exists($body['GROUPS'][0], 'GROUP_ID'));
                self::assertSame('a', $context['payload']['GROUPS'][0]->GROUP_ID);
                self::assertSame('kept', $context['payload']['GROUPS'][0]->ROLE);

                return $body;
            })
            ->post('create', '/groups/{group}/people', fromBody: ['group' => 'GROUPS.*.GROUP_ID'])
            ->build()['config'];
        $this->forward($filter, 'create', '{"GROUPS":[{"GROUP_ID":"a","ROLE":"kept"}],"META":{"0":null}}',
            new Response(201, [], '{"data":{"id":"p"}}'));

        self::assertSame('{"GROUPS":[{"ROLE":"kept"}],"META":{"0":null}}',
            (string) $this->history[0]['request']->getBody());
    }

    public function testExcludedBinaryActionKeepsItsResponseBytes(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID', except: ['image'])
            ->get('image', '/photo')->build()['config'];

        self::assertSame('binary', $this->forward($filter, 'image', '',
            new Response(200, ['Content-Type' => 'image/png'], 'binary')));
    }

    public function testAmbiguousInputNamesRetainNativeExecutionBeforeHttp(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->post('create', '/items')->build()['config'];

        self::assertFalse($this->forward($filter, 'create', '{"NAME":"A","name":"B"}', new Response()));
        self::assertSame([], $this->history);
    }

    public function testAmbiguousUpstreamNamesProduceOneControlledErrorWithoutReplay(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->response(fields: ['created_at' => 'CREATE_DATE'])
            ->get('view', '/items/{id}')->build()['config'];
        $_GET = ['id' => 'a'];
        $result = $this->forward($filter, 'view', '', new Response(200, [],
            '{"data":{"id":"a","created_at":"one","create_date":"two"}}'));

        self::assertSame(502, $this->status);
        self::assertSame('invalid_upstream_response', $this->decoded($result)['error']);
        self::assertCount(1, $this->history);
    }

    public function testRelationNameCollisionsProduceOneControlledErrorWithoutReplay(): void
    {
        $filter = Strangler::proxy('catalog')
            ->uppercase('ITEM_ID')
            ->response(using: static fn (JsonResource $resource): JsonResource => $resource
                ->configureRecord(static fn (Record $record): Record => $record
                    ->one('owner', Record::uppercase(), 'NAME')))
            ->get('view', '/items/{id}')
            ->build()['config'];
        $_GET = ['id' => 'a'];
        $result = $this->forward($filter, 'view', '', new Response(200, [],
            '{"data":{"id":"a","name":"Desk","owner":{"name":"Alice"}}}'));

        self::assertSame(502, $this->status);
        self::assertSame('invalid_upstream_response', $this->decoded($result)['error']);
        self::assertCount(1, $this->history);
    }

    public function testCustomPayloadScopeAndPrecedenceAreIndependentOfFluentOrder(): void
    {
        $callback = static fn (array $body): array => ['custom' => $body['NAME']];
        foreach ([
            Strangler::proxy('catalog')->mapPayload($callback, ['edit'])->uppercase('ITEM_ID'),
            Strangler::proxy('catalog')->uppercase('ITEM_ID')->mapPayload($callback, ['edit']),
        ] as $builder) {
            $config = $builder->post('create', '/items')->put('edit', '/items/{id}')->build()['config'];
            $_GET = [];
            $this->forward($config, 'create', '{"NAME":"Plain"}', new Response(201, [], '{"data":{"id":"a"}}'));
            self::assertSame('{"name":"Plain"}', (string) $this->lastRequest()->getBody());
            $_GET = ['id' => 'a'];
            $this->forward($config, 'edit', '{"NAME":"Override"}', new Response(200, [], '{"data":{"id":"a"}}'));
            self::assertSame('{"custom":"Override"}', (string) $this->lastRequest()->getBody());
        }
    }

    public function testRequestAliasesLeaveResponseAliasesAndOpaqueValuesIndependent(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(fields: ['TITLE' => 'display_name'])
            ->response(fields: ['display_name' => 'NAME'])
            ->post('create', '/items')->build()['config'];
        $result = $this->forward($config, 'create', '{"TITLE":"Desk","META":{"TITLE":"Untouched"}}',
            new Response(200, [], '{"data":{"id":"a","display_name":"Desk"}}'));

        self::assertSame(['display_name' => 'Desk', 'meta' => ['TITLE' => 'Untouched']],
            json_decode((string) $this->history[0]['request']->getBody(), true));
        self::assertSame(['state' => 'data', 'data' => ['ITEM_ID' => 'a', 'NAME' => 'Desk']], $this->decoded($result));
    }

    public function testAnExportedFilterKeepsItsContractWhenTheBuilderIsChanged(): void
    {
        $builder = Strangler::proxy('catalog')->uppercase('ITEM_ID')->get('view', '/items/{id}');
        $first = $builder->build()['config'];
        $second = $builder->response(fields: ['display_name' => 'TITLE'])->build()['config'];
        $_GET = ['id' => 'a'];
        $response = new Response(200, [], '{"data":{"id":"a","display_name":"Desk"}}');
        $original = $this->forward($first, 'view', '', $response);
        $changed = $this->forward($second, 'view', '', $response);

        self::assertArrayHasKey('DISPLAY_NAME', $this->responseData($original));
        self::assertArrayHasKey('TITLE', $this->responseData($changed));
    }

    public function testPassthroughBindingPreservesEmptyAndNumericKeyObjectsAndZeroId(): void
    {
        $filter = Strangler::proxy('catalog')
            ->post('create', '/groups/{group}', fromBody: ['group' => 'GROUP_ID'])
            ->build()['config'];
        $this->forward($filter, 'create', '{"GROUP_ID":0,"meta":{},"numeric":{"0":null}}', new Response(200, [], 'ok'));

        self::assertSame('/groups/0', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('{"meta":{},"numeric":{"0":null}}', (string) $this->history[0]['request']->getBody());
    }

    public function testObjectWithNumericKeysIsNotAcceptedAsAWildcardList(): void
    {
        $filter = Strangler::proxy('catalog')
            ->post('create', '/groups/{group}', fromBody: ['group' => 'GROUPS.*.GROUP_ID'])
            ->build()['config'];

        self::assertFalse($this->forward($filter, 'create', '{"GROUPS":{"0":{"GROUP_ID":"a"}}}', new Response()));
        self::assertSame([], $this->history);
    }

    public function testMultipleBindingsConsumeOriginalIndicesBeforeReindexingLists(): void
    {
        $filter = Strangler::proxy('catalog')
            ->post('create', '/first/{first}/second/{second}', fromBody: [
                'first' => 'ITEMS.0.ID', 'second' => 'ITEMS.1.ID',
            ])->build()['config'];
        $this->forward($filter, 'create', '{"ITEMS":[{"ID":"a"},{"ID":9223372036854775808,"KEEP":false}]}', new Response(200, [], 'ok'));

        self::assertSame('/first/a/second/9223372036854775808', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('{"ITEMS":[{"KEEP":false}]}', (string) $this->history[0]['request']->getBody());
    }

    public function testEquivalentAliasesAreConsumedAndMissingFirstAliasUsesTheSecond(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/groups/{group}/people', fromBody: [
                'group' => ['GROUPS.*.GROUP_ID', 'GROUPS.*.PARENT_GROUP_ID'],
            ])->build()['config'];
        foreach ([['PARENT_GROUP_ID' => 'a'], ['GROUP_ID' => 'a', 'PARENT_GROUP_ID' => 'a']] as $group) {
            $this->forward($filter, 'create', json_encode(['GROUPS' => [$group], 'SN' => 'Name'], JSON_THROW_ON_ERROR),
                new Response(201, [], '{"data":{"id":"p"}}'));
            $request = $this->lastRequest();
            self::assertSame('/groups/a/people', $request->getUri()->getPath());
            self::assertSame('{"sn":"Name"}', (string) $request->getBody());
        }
    }

    public function testBindingCannotReplayNativeAfterAnInvalidUpstreamResponse(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/groups/{group}/people', fromBody: ['group' => 'GROUP_ID'])
            ->build()['config'];
        $result = $this->forward($filter, 'create', '{"GROUP_ID":"a","SN":"Name"}', new Response(200, [], 'not-json'));

        self::assertSame(502, $this->status);
        self::assertSame('invalid_upstream_response', $this->decoded($result)['error']);
        self::assertCount(1, $this->history);
    }

    public function testDefaultPayloadPreservesRootObjectsEmptyObjectsAndLists(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->post('create', '/items')->build()['config'];
        foreach (['{}' => '{}', '{"ITEM_ID":"a"}' => '{}', '{"0":null}' => '{"0":null}', '[]' => '[]'] as $input => $expected) {
            $this->forward($config, 'create', $input, new Response(201, [], '{"data":{"id":"a"}}'));
            self::assertSame($expected, (string) $this->lastRequest()->getBody());
        }
    }

    public function testCallbackMutationCannotChangeLaterOriginalSnapshots(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(parameters: static function (array $context): array {
                self::assertIsArray($context['payload']);
                self::assertInstanceOf(\stdClass::class, $context['payload']['META']);
                $context['payload']['META']->name = 'changed by route callback';

                return [];
            })
            ->mapQuery(static function (array $query, array $context): array {
                self::assertIsArray($context['payload']);
                self::assertInstanceOf(\stdClass::class, $context['payload']['META']);
                self::assertSame('Original', $context['payload']['META']->name);
                $context['payload']['META']->name = 'changed by query callback';

                return [];
            })
            ->mapPayload(static function (array $body, array $context): array {
                self::assertInstanceOf(\stdClass::class, $body['META']);
                self::assertSame('Original', $body['META']->name);
                self::assertIsArray($context['payload']);
                self::assertInstanceOf(\stdClass::class, $context['payload']['META']);
                self::assertSame('Original', $context['payload']['META']->name);
                self::assertNotSame($body['META'], $context['payload']['META']);
                $body['META']->name = 'changed in body';
                self::assertSame('changed in body', $body['META']->name);

                return ['name' => 'Saved'];
            })
            ->mapJsonResponse(static function ($json, $response, array $context) {
                self::assertIsArray($context['payload']);
                self::assertInstanceOf(\stdClass::class, $context['payload']['META']);
                self::assertSame('Original', $context['payload']['META']->name);

                return Json::response($response, ['saved' => true]);
            })
            ->post('create', '/items')->build()['config'];
        self::assertSame('{"saved":true}', $this->forward($config, 'create', '{"META":{"name":"Original"}}',
            new Response(201, [], '{"data":{"id":"a"}}')));
    }

    public function testValueDifferencesComposeWithNamingAndPreserveOpaqueKeys(): void
    {
        $filter = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(values: ['meta' => static fn ($value) => json_encode($value, JSON_THROW_ON_ERROR)])
            ->response(values: ['meta' => static function ($value): mixed {
                self::assertIsString($value);

                return json_decode($value, flags: JSON_THROW_ON_ERROR);
            }])
            ->post('create', '/items')->build()['config'];
        $result = $this->forward($filter, 'create', '{"META":{"OriginalCase":false,"0":null}}',
            new Response(201, [], '{"data":{"id":"a","meta":"{\"OriginalCase\":false,\"0\":null}"}}'));

        self::assertSame(['meta' => '{"OriginalCase":false,"0":null}'],
            json_decode((string) $this->history[0]['request']->getBody(), true));
        self::assertSame(['state' => 'data', 'data' => ['ITEM_ID' => 'a', 'META' => ['OriginalCase' => false, 0 => null]]], $this->decoded($result));
    }

    public function testNativeParentLookupValuesAreRouteOnlyAndMissingScopeStaysNative(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(parameters: static function (array $context): array|false {
                return ($context['query']['id'] ?? null) === 'missing' ? false : ['parent' => 'owner/a'];
            })
            ->put('edit', '/owners/{parent}/items/{id}')->build()['config'];
        $_GET = ['id' => 'item'];
        $this->forward($config, 'edit', '{"ITEM_ID":"item","VALUE":"Saved"}',
            new Response(200, [], '{"data":{"id":"item"}}'));
        self::assertSame('/owners/owner%2Fa/items/item', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('', $this->history[0]['request']->getUri()->getQuery());
        self::assertSame('{"value":"Saved"}', (string) $this->history[0]['request']->getBody());
        $_GET = ['id' => 'missing'];
        self::assertFalse($this->forward($config, 'edit', '{"VALUE":"Unsaved"}', new Response()));
        self::assertCount(1, $this->history);
    }

    public function testComputedRouteBindingsRejectConflictsAndInvalidSegmentsBeforeHttp(): void
    {
        foreach (['', '.', '..', 'different'] as $parent) {
            $config = Strangler::proxy('catalog')
                ->request(parameters: static fn (): array => ['parent' => $parent])
                ->post('create', '/parents/{parent}', fromBody: ['parent' => 'PARENT_ID'])
                ->build()['config'];

            self::assertFalse($this->forward($config, 'create', '{"PARENT_ID":"original"}', new Response()));
        }
        self::assertSame([], $this->history);

        $config = Strangler::proxy('catalog')
            ->request(parameters: static fn (): array => ['parent' => 'original'])
            ->post('create', '/parents/{parent}', fromBody: ['parent' => 'PARENT_ID'])
            ->build()['config'];
        self::assertSame('saved', $this->forward($config, 'create', '{"PARENT_ID":"original"}', new Response(200, [], 'saved')));
        self::assertSame('/parents/original', $this->lastRequest()->getUri()->getPath());
        self::assertCount(1, $this->history);
    }

    public function testValueFormattersSkipAbsentFieldsAndReceiveExplicitNulls(): void
    {
        $inputValues = [];
        $outputValues = [];
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(values: ['note' => static function (mixed $value) use (&$inputValues): string {
                $inputValues[] = $value;

                return 'encoded null';
            }])
            ->response(values: ['note' => static function (mixed $value) use (&$outputValues): string {
                $outputValues[] = $value;

                return 'decoded null';
            }])
            ->post('create', '/items')->build()['config'];
        $absent = $this->forward($config, 'create', '{}', new Response(201, [], '{"data":{"id":"a"}}'));
        self::assertSame('{}', (string) $this->lastRequest()->getBody());
        self::assertSame(['ITEM_ID' => 'a'], $this->responseData($absent));
        self::assertSame([], $inputValues);
        self::assertSame([], $outputValues);

        $present = $this->forward($config, 'create', '{"NOTE":null}', new Response(201, [], '{"data":{"id":"a","note":null}}'));
        self::assertSame('{"note":"encoded null"}', (string) $this->lastRequest()->getBody());
        self::assertSame(['ITEM_ID' => 'a', 'NOTE' => 'decoded null'], $this->responseData($present));
        self::assertSame([null], $inputValues);
        self::assertSame([null], $outputValues);
    }

    public function testCustomStagesReplaceValueFormattersInEitherFluentOrder(): void
    {
        $formatter = static function (mixed $value): mixed {
            self::fail('A custom stage must replace the convention formatter.');
        };
        foreach ([false, true] as $customFirst) {
            $builder = Strangler::proxy('catalog');
            $customize = static fn (Strangler $proxy): Strangler => $proxy
                ->mapPayload(static fn (array $body): array => ['saved' => $body['NOTE']])
                ->mapJsonResponse(static fn (mixed $json, ResponseInterface $response): ResponseInterface => Json::response($response, ['custom' => true]));
            if ($customFirst) {
                $customize($builder);
            }
            $builder->uppercase('ITEM_ID')
                ->request(values: ['note' => $formatter])->response(values: ['note' => $formatter]);
            if (! $customFirst) {
                $customize($builder);
            }
            $result = $this->forward($builder->post('create', '/items')->build()['config'], 'create',
                '{"NOTE":"original"}', new Response(201, [], '{"data":{"id":"a","note":"upstream"}}'));

            self::assertSame('{"saved":"original"}', (string) $this->lastRequest()->getBody());
            self::assertSame(['custom' => true], $this->decoded($result));
        }
    }

    public function testMalformedBodyBindingsAreRejectedDuringConfiguration(): void
    {
        foreach ([
            ['missing' => 'GROUP_ID'], ['parent'        => ''], ['parent' => '.GROUP_ID'],
            ['parent' => 'GROUPS..GROUP_ID'], ['parent' => 'GROUPS.'], ['parent' => []],
        ] as $binding) {
            try {
                Strangler::proxy('catalog')->post('create', '/parents/{parent}', fromBody: $binding)->build();
                self::fail('Malformed body bindings must fail before a request is handled.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Body', $error->getMessage());
            }
        }
    }

    public function testMalformedConventionDeclarationsAreRejectedImmediately(): void
    {
        $formatter = static fn (mixed $value): mixed => $value;
        foreach ([
            static fn (): Strangler => Strangler::proxy('catalog')->response(fields: ['name' => 'TITLE', 'title' => 'TITLE']),
            static fn (): Strangler => Strangler::proxy('catalog')->request(values: ['' => $formatter]),
            static fn (): Strangler => Strangler::proxy('catalog')->response(values: ['' => $formatter]),
        ] as $configure) {
            try {
                $configure();
                self::fail('Malformed conventions must fail at their configuration call.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testEveryDirectionalExceptionRequiresANativeIdentifierBeforeBuild(): void
    {
        $formatter = static fn (mixed $value): mixed => $value;
        foreach ([
            Strangler::proxy('catalog')->response(fields: ['name' => 'TITLE']),
            Strangler::proxy('catalog')->request(fields: ['TITLE' => 'name']),
            Strangler::proxy('catalog')->request(values: ['name' => $formatter]),
            Strangler::proxy('catalog')->response(values: ['name' => $formatter]),
        ] as $builder) {
            try {
                $builder->get('view', '/items')->build();
                self::fail('A directional exception without a native identifier must fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('native identifier', $error->getMessage());
            }
        }
    }

    public function testRepeatedDirectionalDeclarationsAccumulateAndReplaceMatchingEntries(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID')
            ->request(fields: ['OLD_TITLE' => 'wrong', 'NOTE' => 'description'])
            ->request(fields: ['OLD_TITLE' => 'title'])
            ->response(fields: ['title' => 'WRONG', 'description' => 'NOTE'])
            ->response(fields: ['title' => 'TITLE'])
            ->request(values: ['title' => static fn (): string => 'wrong', 'description' => static fn (): string => 'encoded note'])
            ->request(values: ['title' => static fn (): string => 'encoded title'])
            ->response(values: ['title' => static fn (): string => 'wrong', 'description' => static fn (): string => 'decoded note'])
            ->response(values: ['title' => static fn (): string => 'decoded title'])
            ->post('create', '/items')->build()['config'];
        $result = $this->forward($config, 'create', '{"OLD_TITLE":"input","NOTE":null}',
            new Response(201, [], '{"data":{"id":"a","title":"upstream","description":null}}'));

        self::assertSame(['title' => 'encoded title', 'description' => 'encoded note'],
            $this->decoded((string) $this->lastRequest()->getBody()));
        self::assertSame(['ITEM_ID' => 'a', 'TITLE' => 'decoded title', 'NOTE' => 'decoded note'], $this->responseData($result));
    }

    public function testMixedCaseExcludedActionsRetainRawTransportAndOtherActionsKeepConventions(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('ITEM_ID', except: ['ImAgE'])
            ->post('image', '/images')->post('create', '/items')->build()['config'];
        $_GET = ['RAW' => 'query'];
        self::assertSame('binary', $this->forward($config, 'image', '{"RAW":"body"}',
            new Response(200, ['Content-Type' => 'image/png'], 'binary')));
        self::assertSame('RAW=query', $this->lastRequest()->getUri()->getQuery());
        self::assertSame('{"RAW":"body"}', (string) $this->lastRequest()->getBody());

        $_GET = [];
        $result = $this->forward($config, 'create', '{"NAME":"Desk"}', new Response(201, [], '{"data":{"id":"a","name":"Desk"}}'));
        self::assertSame('{"name":"Desk"}', (string) $this->lastRequest()->getBody());
        self::assertSame(['ITEM_ID' => 'a', 'NAME' => 'Desk'], $this->responseData($result));
    }

    public function testCustomQueryScopeAndPrecedenceAreIndependentOfFluentOrder(): void
    {
        $callback = static fn (array $query): array => ['search' => $query['TERM']];
        foreach ([
            Strangler::proxy('catalog')->mapQuery($callback, ['search'])->uppercase('ITEM_ID'),
            Strangler::proxy('catalog')->uppercase('ITEM_ID')->mapQuery($callback, ['search']),
        ] as $builder) {
            $config = $builder->get('search', '/search')->get('get', '/items')->build()['config'];
            $_GET = ['TERM' => 'Desk'];
            $this->forward($config, 'search', '', new Response(200, [], '{"data":{"id":"a"}}'));
            self::assertSame('search=Desk', $this->lastRequest()->getUri()->getQuery());

            $_GET = ['filter' => ['NAME' => 'Desk']];
            $this->forward($config, 'get', '', new Response(200, [], '{"data":[],"meta":{"total":0}}'));
            parse_str($this->lastRequest()->getUri()->getQuery(), $query);
            self::assertSame(['name' => 'Desk'], $query['filter']);
            self::assertSame('1', $query['page']);
        }
    }

    public function testRequestOnlyAliasesDoNotRenameFiltersOrSorts(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->fields(['category' => 'CATEGORY_ID'])
            ->request(fields: ['DISPLAY_NAME' => 'name'])
            ->query(filters: ['display_name' => 'query'])
            ->get('get', '/people')->build()['config'];
        $_GET = ['filter' => ['DISPLAY_NAME' => 'Alice'], 'sort' => ['DISPLAY_NAME' => 'ASC']];
        $this->forward($config, 'get', '', new Response(200, [], '{"data":[],"meta":{"total":0}}'));
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['query' => 'Alice'], $query['filter']);
        self::assertSame('display_name', $query['sort']);
    }

    public function testValidationFieldNamesDoNotDependOnUnrelatedSharedAliases(): void
    {
        foreach ([false, true] as $shared) {
            $builder = Strangler::proxy('catalog')->uppercase('PERSON_ID')
                ->request(fields: ['TITLE' => 'name'])
                ->mapJsonErrors(static fn ($json, $response, $context) => (new ErrorResponse($context['errorFields'] ?? [], []))($json, $response));
            if ($shared) {
                $builder->fields(['category' => 'CATEGORY_ID']);
            }
            $result = $this->forward($builder->post('create', '/people')->build()['config'],
                'create', '{"TITLE":""}', new Response(422, [], '{"errors":{"name":["required"]}}'));
            self::assertSame(['TITLE' => ['required' => []]], $this->decoded($result)['meta']);
        }
    }

    public function testValidationUsesTheInputAliasActuallySupplied(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->request(fields: ['DISPLAY_NAME' => 'name', 'TITLE' => 'name'])
            ->mapJsonErrors(static fn ($json, $response, $context) => (new ErrorResponse($context['errorFields'] ?? [], []))($json, $response))
            ->post('create', '/people')->build()['config'];
        foreach (['DISPLAY_NAME', 'TITLE'] as $field) {
            $result = $this->forward($config, 'create', json_encode([$field => ''], JSON_THROW_ON_ERROR),
                new Response(422, [], '{"errors":{"name":["required"]}}'));
            self::assertSame([$field => ['required' => []]], $this->decoded($result)['meta']);
        }
    }

    public function testSharedFieldsTranslateWritesFiltersSortsResponsesAndErrors(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->fields(['name' => 'TITLE'])
            ->response(fields: ['parent_id' => 'PARENT_GROUP_ID'])
            ->mapJsonErrors(static fn ($json, $response, $context) => (new ErrorResponse($context['errorFields'] ?? $context['responseFields'] ?? [], []))($json, $response))
            ->post('create', '/people')->get('get', '/people')->build()['config'];
        $result = $this->forward($config, 'create', '{"TITLE":"today","SN":"Name"}',
            new Response(201, [], '{"data":{"id":"p","name":"today"}}'));
        self::assertSame('{"name":"today","sn":"Name"}', (string) $this->lastRequest()->getBody());
        self::assertSame(['PERSON_ID' => 'p', 'TITLE' => 'today'], $this->responseData($result));

        $_GET = ['filter' => ['TITLE' => 'today'], 'sort' => ['TITLE' => 'DESC']];
        $this->forward($config, 'get', '', new Response(200, [], '{"data":[],"meta":{"total":0}}'));
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['name' => 'today'], $query['filter']);
        self::assertSame('-name', $query['sort']);

        $_GET = [];
        $result = $this->forward($config, 'create', '{"TITLE":"invalid"}',
            new Response(422, [], '{"errors":{"name":["invalid"],"parent_id":["invalid"]}}'));
        $meta = $this->decoded($result)['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('TITLE', $meta);
        self::assertArrayHasKey('PARENT_GROUP_ID', $meta);
    }

    public function testDirectionalExceptionsOverrideSharedFieldsIndependentlyOfCallOrder(): void
    {
        foreach ([true, false] as $sharedFirst) {
            $builder = Strangler::proxy('catalog')->uppercase('PERSON_ID');
            if ($sharedFirst) {
                $builder->fields(['name' => 'TITLE']);
            }
            $builder->request(fields: ['TITLE' => 'input_name'])
                ->response(fields: ['name' => 'OUTPUT_NAME'])
                ->mapJsonErrors(static fn ($json, $response, $context) => (new ErrorResponse($context['errorFields'] ?? [], []))($json, $response));
            if (! $sharedFirst) {
                $builder->fields(['name' => 'TITLE']);
            }
            $config = $builder->get('get', '/people')->post('create', '/people')->build()['config'];
            $_GET = [];
            $result = $this->forward($config, 'create', '{"TITLE":"today"}',
                new Response(201, [], '{"data":{"id":"p","name":"today"}}'));
            self::assertSame('{"input_name":"today"}', (string) $this->lastRequest()->getBody());
            self::assertSame(['PERSON_ID' => 'p', 'OUTPUT_NAME' => 'today'], $this->responseData($result));
            $_GET = ['filter' => ['TITLE' => 'today'], 'sort' => ['TITLE' => 'ASC']];
            $this->forward($config, 'get', '', new Response(200, [], '{"data":[],"meta":{"total":0}}'));
            parse_str($this->lastRequest()->getUri()->getQuery(), $query);
            self::assertSame(['name' => 'today'], $query['filter']);
            self::assertSame('name', $query['sort']);
            $_GET = [];
            $result = $this->forward($config, 'create', '{"TITLE":"invalid"}',
                new Response(422, [], '{"errors":{"input_name":["invalid"]}}'));
            $meta = $this->decoded($result)['meta'];
            self::assertIsArray($meta);
            self::assertArrayHasKey('TITLE', $meta);
            self::assertArrayNotHasKey('OUTPUT_NAME', $meta);
        }
    }

    public function testRouteCallbacksUseOriginalValuesAndOnlyRunForTheirAction(): void
    {
        $calls = [];
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->put('move', '/people/{id}/groups',
                parameters: static function (array $query, array $payload) use (&$calls): array {
                    $calls[] = [$query, $payload];

                    return ['id' => $payload['PERSON_ID']];
                },
                body: static fn (array $payload): array => ['ids' => [$payload['PARENT_GROUP_ID']]],
            )
            ->post('create', '/people')->build()['config'];
        $_GET = ['filter' => ['SN' => 'Name']];
        $this->forward($config, 'move', '{"PERSON_ID":"p/a","PARENT_GROUP_ID":"g"}',
            new Response(200, [], '{"data":{"id":"p/a"}}'));
        self::assertSame('/people/p%2Fa/groups', $this->lastRequest()->getUri()->getPath());
        self::assertSame('{"ids":["g"]}', (string) $this->lastRequest()->getBody());
        self::assertSame([[['filter' => ['SN' => 'Name']], ['PERSON_ID' => 'p/a', 'PARENT_GROUP_ID' => 'g']]], $calls);
        $_GET = [];
        $this->forward($config, 'create', '{"SN":"Name"}', new Response(201, [], '{"data":{"id":"p"}}'));
        self::assertCount(1, $calls);
        self::assertSame('{"sn":"Name"}', (string) $this->lastRequest()->getBody());
    }

    public function testRouteBodyAutomaticallyAppliesConfiguredNamingAndNestedValueConversions(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->fields(['name' => 'TITLE'])
            ->request(values: [
                'contacts' => Mapping::recordsWith(
                    Fields::uppercase(),
                    values: ['meta' => Format::jsonString(...)],
                ),
            ])
            ->post('create', '/people', body: static function (array $payload): array {
                unset($payload['GROUPS']);

                return $payload;
            })->build()['config'];
        $this->forward($config, 'create', '{"PERSON_ID":"p","GROUPS":[{"GROUP_ID":"g"}],"TITLE":"today","CONTACTS":[{"META":{"KeepCase":true}}]}',
            new Response(201, [], '{"data":{"id":"p"}}'));
        self::assertSame([
            'name' => 'today', 'contacts' => [['meta' => '{"KeepCase":true}']],
        ], json_decode((string) $this->lastRequest()->getBody(), true));
        self::assertFalse($this->forward($config, 'create', '{"TITLE":"today","name":"other"}', new Response(201)));
        self::assertCount(1, $this->history);
    }

    public function testResponseBodyModifierRetainsSharedFieldRulesAndCanReturnEmptyOrErrorResults(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->fields(['change_date' => 'UPDATED_AT'])
            ->response(body: static function (mixed $body, array $context): mixed {
                if ($context['action'] === 'copy') {
                    return new Response(200, ['Content-Type' => 'application/json'], '{"state":"data"}');
                }
                if ($context['action'] === 'link') {
                    return new Response(400, ['Content-Type' => 'application/json'],
                        '{"state":"error","error":"exists_entity"}');
                }
                self::assertSame(201, $context['status']);
                self::assertInstanceOf(\stdClass::class, $body);
                $body->data = $body->person;
                unset($body->person);

                return $body;
            })
            ->post('create', '/people')->put('copy', '/people/{id}/groups')
            ->put('link', '/people/{id}/workstations')->build()['config'];
        $result = $this->forward($config, 'create', '{}',
            new Response(201, [], '{"person":{"id":"p","change_date":"today"}}'));
        self::assertSame(['state' => 'data', 'data' => ['PERSON_ID' => 'p', 'UPDATED_AT' => 'today']], $this->decoded($result));
        $_GET = ['id' => 'p'];
        self::assertSame(['state' => 'data'], $this->decoded($this->forward(
            $config, 'copy', '{}', new Response(204),
        )));
        self::assertSame(200, $this->status);
        self::assertSame(['state' => 'error', 'error' => 'exists_entity'], $this->decoded($this->forward(
            $config, 'link', '{}', new Response(200, [], '{"data":{}}'),
        )));
        self::assertSame(400, $this->status);
        self::assertCount(3, $this->history);
    }

    public function testRouteBodyCanKeepYiiBeforeAnyHttpAttempt(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/people', body: static fn (array $payload): false => false)->build()['config'];
        self::assertFalse($this->forward($config, 'create', '{}', new Response(201)));
        self::assertSame([], $this->history);
    }

    public function testBinaryRouteKeepsBytesAndStillMapsErrors(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->mapJsonErrors(static fn ($json, $response) => (new ErrorResponse([], []))($json, $response))
            ->fields(['name' => 'TITLE'])
            ->get('image', '/people/{id}/photo', binary: true)->build()['config'];
        $_GET = ['id' => 'p', 'filter' => ['TITLE' => 'Alice'], 'sort' => ['TITLE' => 'DESC']];
        self::assertSame("\x89PNG\x00", $this->forward($config, 'image', '',
            new Response(200, ['Content-Type' => 'image/png'], "\x89PNG\x00")));
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame(['name' => 'Alice'], $query['filter']);
        self::assertSame('-name', $query['sort']);
        $result = $this->forward($config, 'image', '', new Response(404, [], '{"code":"not_found"}'));
        self::assertSame(['state' => 'error', 'error' => 'not_found'], $this->decoded($result));
    }

    public function testRouteBodyReceivesOriginalFieldsConsumedByBodyBindings(): void
    {
        $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
            ->post('create', '/groups/{group}/people',
                fromBody: ['group' => 'GROUPS.*.GROUP_ID'],
                body: static fn (array $payload): array => ['original_groups' => $payload['GROUPS']],
            )->build()['config'];
        $this->forward($config, 'create', '{"GROUPS":[{"GROUP_ID":"g"}]}',
            new Response(201, [], '{"data":{"id":"p"}}'));
        self::assertSame('/groups/g/people', $this->lastRequest()->getUri()->getPath());
        self::assertSame('{"original_groups":[{"GROUP_ID":"g"}]}', (string) $this->lastRequest()->getBody());
    }

    public function testRouteCallbacksCanKeepTheRequestNativeBeforeTransport(): void
    {
        foreach (['parameters', 'body'] as $stage) {
            $options = [$stage => static fn (): false => false];
            $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
                ->post('create', '/people', ...$options)->build()['config'];
            self::assertFalse($this->forward($config, 'create', '{}', new Response(201)));
        }
        self::assertSame([], $this->history);
    }

    public function testRouteParameterConflictsAndInvalidValuesStayNative(): void
    {
        foreach ([['parent' => 'other'], ['parent' => []], ['parent' => null]] as $parameters) {
            $config = Strangler::proxy('catalog')->uppercase('PERSON_ID')
                ->request(parameters: static fn (): array => ['parent' => 'original'])
                ->post('create', '/parents/{parent}/people', parameters: static fn (): array => $parameters)
                ->build()['config'];
            self::assertFalse($this->forward($config, 'create', '{}', new Response(201)));
        }
        self::assertSame([], $this->history);
    }

    /** @return array<array-key, mixed> */
    private function decoded(string|false $response): array
    {
        self::assertIsString($response);
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private function responseData(string|false $response): array
    {
        $data = $this->decoded($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    private function lastRequest(): RequestInterface
    {
        $history = $this->history;
        $last = array_key_last($history);
        self::assertNotNull($last);
        self::assertArrayHasKey($last, $history);

        return $history[$last]['request'];
    }

    /**
     * @param Config $config
     */
    private function forward(array $config, string $action, string $body, Response $response): string|false
    {
        $application = Yii::app();
        self::assertInstanceOf(WebApplication::class, $application);
        $request = $application->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->body = $body;
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::tap(function (RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        }));
        $emitter = new ResponseEmitter(static function (string $header): void {}, function (int $status): void {
            $this->status = $status;
        });
        ob_start();

        try {
            $handled = (new StranglerProxy(new Client(['handler' => $stack]), $emitter))
                ->handle(new Controller('catalog'), $config, $action);
            self::assertFalse($handled);

            return false;
        } catch (ApplicationEnded) {
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
