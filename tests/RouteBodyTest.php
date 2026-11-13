<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\InlineMapping;
use Cosmira\Strangler\Response\ErrorResponse;
use Cosmira\Strangler\Schema\Mapping;
use Cosmira\Strangler\Strangler;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class RouteBodyTest extends TestCase
{
    public function testRouteBodyCanBeDeclaredBeforeTheYiiApplicationStarts(): void
    {
        \Yii::setApplication(null);
        $config = Strangler::proxy('example')->put('move', '/links', body: ['ids' => ['LINK_ID']])->build()['config'];
        $mapping = new InlineMapping($config['mappings'] ?? [], ['action' => 'move', 'query' => [], 'payload' => ['LINK_ID' => 'one']]);
        self::assertSame(['ids' => ['one']], $mapping->request([], [], null)['payload'] ?? null);
    }

    public function testRoutesConstructBodiesWithoutSendingUnselectedNativeFields(): void
    {
        foreach (['get', 'post', 'put', 'delete'] as $method) {
            $config = Strangler::proxy('example')->uppercase('PERSON_ID')
                ->{$method}('move', '/api/people/{id}/groups', body: [
                    'ids'   => ['PARENT_GROUP_ID', 'OTHER_GROUP_ID'],
                    'note'  => 'NOTE',
                    'empty' => [],
                ])->build()['config'];
            $native = ['PERSON_ID' => 'p', 'PARENT_GROUP_ID' => 'g', 'OTHER_GROUP_ID' => 0, 'NOTE' => null, 'EXTRA' => true];
            $mapping = new InlineMapping($config['mappings'] ?? [], [
                'action' => 'move', 'query' => ['id' => 'p'], 'payload' => $native,
            ]);

            $result = $mapping->request([], $native, null);

            self::assertIsArray($result);

            self::assertSame(['ids' => ['g', 0], 'note' => null, 'empty' => []], $result['payload']);
            self::assertSame($native, $mapping->context()['payload']);
        }
    }

    public function testPatchBodyKeepsNullForMissingSourcesAndCanBeExplicitlyEmpty(): void
    {
        foreach ([['remove' => ['MISSING']], []] as $body) {
            $config = Strangler::proxy('example')->uppercase('PERSON_ID')
                ->route('unlink', 'PATCH', '/people/{id}/groups', body: $body)
                ->build()['config'];
            $mapping = new InlineMapping($config['mappings'] ?? [], [
                'action' => 'unlink', 'query' => ['id' => 'p'], 'payload' => ['PERSON_ID' => 'p'],
            ]);

            $result = $mapping->request([], ['PERSON_ID' => 'p'], null);

            self::assertIsArray($result);

            self::assertSame($body === [] ? [] : ['remove' => [null]], $result['payload']);
        }
    }

    public function testBodyCanApplyAReusableRecordConversion(): void
    {
        $config = Strangler::proxy('example')->uppercase('PERSON_ID')
            ->put('setstatus', '/people/{id}/statuses', body: [
                'statuses' => Mapping::field(
                    'STATUSES',
                    Mapping::recordsWith(
                        ['IDENTITY_STATUS_ID' => 'status_id'], defaults: ['note' => null],
                    ),
                    default: [],
                ),
            ])->build()['config'];
        $payload = ['STATUSES' => [['IDENTITY_STATUS_ID' => 's']]];
        $mapping = new InlineMapping($config['mappings'] ?? [], [
            'action' => 'setstatus', 'query' => [], 'payload' => $payload,
        ]);

        $result = $mapping->request([], $payload, null);

        self::assertIsArray($result);

        self::assertSame(['statuses' => [['status_id' => 's', 'note' => null]]], $result['payload']);
        $result = $mapping->withPayload([], '{}')->request([], [], null);
        self::assertIsArray($result);
        self::assertSame(['statuses' => []], $result['payload']);
    }

    public function testDeclaredEmptyResultsRetainErrorAndStrictEnvelopeBehavior(): void
    {
        $config = Strangler::proxy('example')->uppercase('PERSON_ID')
            ->response(empty: ['copy'], rejectEmpty: [
                'copy' => [['meta', 'added_ids'], 'exists_entity', 400],
            ])->route('copy', 'PATCH', '/people/{id}/groups')->build()['config'];
        $mapping = new InlineMapping($config['mappings'] ?? [], ['action' => 'copy', 'query' => [], 'payload' => []]);
        foreach ([[[], 400, ['state' => 'error', 'error' => 'exists_entity']], [['g'], 200, ['state' => 'data']]] as [$ids, $status, $expected]) {
            $response = $mapping->response(new Response(200, [], json_encode([
                'data' => ['id' => 'p'], 'meta' => ['added_ids' => $ids],
            ], JSON_THROW_ON_ERROR)));

            self::assertSame($status, $response->getStatusCode());
            self::assertSame($expected, json_decode((string) $response->getBody(), true));
        }
        self::assertSame(502, $mapping->response(new Response(200, [], '{}'))->getStatusCode());
    }

    public function testBodyMappingsAreValidatedDuringDeclaration(): void
    {
        foreach ([['' => 'ID'], ['ids' => [1]], ['ids' => ['key' => 'ID']], ['ids' => '']] as $body) {
            try {
                (new \ReflectionMethod(Strangler::class, 'put'))->invoke(
                    Strangler::proxy('example'), 'move', '/items/{id}', [], $body,
                );
                self::fail('Malformed body declaration was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testErrorRedirectRetainsOtherNativeErrors(): void
    {
        $fallback = static function (mixed $json, ResponseInterface $response, array $context): ResponseInterface {
            self::assertSame(['action' => 'image'], $context);

            return new Response(403, [], 'image');
        };
        $redirect = ErrorResponse::redirect(404, '/avatar.jpg', $fallback);
        $response = $redirect([], new Response(404), ['action' => 'image']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/avatar.jpg', $response->getHeaderLine('Location'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame('image', (string) $redirect([], new Response(403), ['action' => 'image'])->getBody());
        $this->expectException(InvalidArgumentException::class);
        ErrorResponse::redirect(200, '/avatar.jpg', $fallback);
    }
}
