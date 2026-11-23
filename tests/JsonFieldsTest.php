<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use CController;
use Cosmira\Strangler\ControllerContract;
use Cosmira\Strangler\ResponseEmitter;
use Cosmira\Strangler\Strangler;
use Cosmira\Strangler\StranglerProxy;
use Cosmira\Strangler\Tests\Fixtures\Controller;
use Cosmira\Strangler\Tests\Fixtures\Request;
use Cosmira\Strangler\Tests\Fixtures\User;
use Cosmira\Strangler\Tests\Fixtures\WebApplication;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Yii;

final class JsonFieldsTest extends TestCase
{
    private Controller $controller;

    private Request $request;

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<string> */
    private array $headers = [];

    protected function setUp(): void
    {
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/bootstrap.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php';
        Yii::setApplication(null);
        $app = new WebApplication([
            'basePath'   => __DIR__, 'runtimePath' => __DIR__,
            'components' => [
                'request' => ['class' => Request::class],
                'user'    => ['class' => User::class],
            ],
            'params' => ['strangler' => ['base_uri' => 'https://catalog.test']],
        ]);
        $app->shouldEnd = false;
        $request = $app->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $this->request = $request;
        $this->controller = new Controller('catalog');
    }

    protected function tearDown(): void
    {
        Yii::setApplication(null);
    }

    public function testWriteSelectsAndNormalizesOnlyDeclaredFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->request->body = '{"LABEL":"Ink","label":"Ink","InStock":1,"instock":true,"count":-4,"tags":["a","b"],"note":null,"private":1,"PRIVATE":2}';
        $proxy = Strangler::proxy('catalog')
            ->put('save', '/items', incoming: 'POST')
            ->jsonFields(['label' => 'string', 'inStock' => 'bool', 'count' => 'int', 'tags' => 'list<string>', 'note' => 'string|null']);
        $result = $this->dispatch($proxy, 'save', new Response(201, ['X-Request-Id' => 'catalog-1'], '{"data":{"label":"Ink","inStock":false,"count":0,"tags":[],"note":"ok","private":42}}'));

        self::assertSame('{"state":"data","data":{"label":"Ink","inStock":false,"count":0,"tags":[],"note":"ok"}}', $result);
        self::assertSame('PUT', $this->sent[0]->getMethod());
        self::assertSame('{"label":"Ink","inStock":true,"count":-4,"tags":["a","b"],"note":null}', (string) $this->sent[0]->getBody());
        self::assertSame(201, http_response_code());
        self::assertContains('X-Request-Id: catalog-1', $this->headers);
    }

    #[TestWith(['[]'])]
    #[TestWith(['{}'])]
    #[TestWith(['null'])]
    #[TestWith(['{"tags":{},"label":"x"}'])]
    #[TestWith(['{"tags":{"0":"a"},"label":"x"}'])]
    #[TestWith(['{"tags":[1],"label":"x"}'])]
    #[TestWith(['{"tags":[],"label":9223372036854775808}'])]
    #[TestWith(['{"tags":[],"label":false}'])]
    #[TestWith(['{"tags":["a"]}'])]
    #[TestWith(['{"tags":[],"label":"a","LABEL":"b"}'])]
    public function testUnsupportedJsonStaysNativeBeforeAnyWrite(string $body): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $this->request->body = $body;
        $proxy = Strangler::proxy('catalog')->jsonFields(['label' => 'string|null', 'tags' => 'list<string>'])
            ->patch('save', '/items');

        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        self::assertSame([], $this->sent);
    }

    #[TestWith(['{}'])]
    #[TestWith(['[]'])]
    #[TestWith(['{"data":{}}'])]
    #[TestWith(['{"data":[]}'])]
    #[TestWith(['{"data":{"tags":{},"label":null,"enabled":true}}'])]
    #[TestWith(['{"data":{"tags":["a"],"label":null,"enabled":0}}'])]
    #[TestWith(['{"data":{"tags":[],"label":9223372036854775808,"enabled":true}}'])]
    public function testMalformedSuccessIsReportedAfterOneRequest(string $body): void
    {
        $proxy = Strangler::proxy('catalog')->jsonFields(['tags' => 'list<string>', 'label' => 'string|null', 'enabled' => 'bool'])
            ->get('show', '/items');

        self::assertSame('{"state":"error","error":"invalid_upstream_response"}', $this->dispatch($proxy, 'show', new Response(200, [], $body)));
        self::assertSame(502, http_response_code());
        self::assertCount(1, $this->sent);
    }

    public function testModelFreeGroupRetainsTheApplicationReaderAndFinalValidation(): void
    {
        $contract = new class extends ControllerContract
        {
            public int $reads = 0;
            public int $validations = 0;

            public function payload(CController $controller): array
            {
                $this->reads++;

                return ['ENABLED' => 0, 'tags' => null, 'COUNT' => null];
            }

            public function validateResponse(ResponseInterface $response): void
            {
                $this->validations++;
            }
        };
        $app = StranglerProxy::application();
        $app->getParams()->add('strangler', ['base_uri' => 'https://catalog.test', 'controller_contract' => $contract::class]);
        $proxy = Strangler::proxy('catalog')->get('get', '/stock')->put('edit', '/stock')
            ->jsonFields(['enabled' => 'bool|null', 'tags' => 'list<string>|null', 'count' => 'int|null']);
        $registered = $proxy->build()['config']['controllerContract'] ?? null;
        self::assertInstanceOf($contract::class, $registered);
        $this->request->body = 'invalid';
        self::assertSame('{"state":"data"}', $this->dispatch($proxy, 'get', new Response(200, [], '{"data":null}')));
        self::assertSame('', (string) $this->sent[0]->getBody());
        self::assertSame(0, $registered->reads);
        self::assertSame(1, $registered->validations);

        self::assertFalse($this->dispatch($proxy, 'edit', new Response(200)));
        self::assertSame(0, $registered->reads);
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $result = $this->dispatch($proxy, 'edit', new Response(422, [], '{"errors":{"enabled":["required"]}}'));
        self::assertSame('{"state":"error","error":"validation","meta":{"ENABLED":{"required":[]}}}', $result);
        self::assertSame(400, http_response_code());
        self::assertSame('{"enabled":false,"tags":null,"count":null}', (string) $this->sent[1]->getBody());
        self::assertSame(1, $registered->reads);
        self::assertCount(2, $this->sent);

        $this->expectException(\LogicException::class);
        $registered->model($this->controller);
    }

    public function testRouteBodySelectionChecksTheSelectedObjectBeforeSending(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $proxy = Strangler::proxy('catalog')->jsonFields(['enabled' => 'bool', 'tags' => 'list<string>'])
            ->put('save', '/items', body: static function (array $form): \stdClass|false {
                $preferences = $form['preferences'] ?? null;

                return $preferences instanceof \stdClass ? $preferences : false;
            });

        $this->request->body = '{"preferences":{"ENABLED":0,"tags":{},"secret":"private"}}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        $this->request->body = '{"preferences":[]}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));

        $this->request->body = '{"preferences":{"ENABLED":0,"tags":[],"secret":"private"}}';
        self::assertSame('{"state":"data"}', $this->dispatch($proxy, 'save', new Response(200, [], '{"data":null}')));
        self::assertCount(1, $this->sent);
        self::assertSame('{"enabled":false,"tags":[]}', (string) $this->sent[0]->getBody());
    }

    public function testSelectRowPreservesNestedJsonObjectsAndOutOfRangeNumbers(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $proxy = Strangler::proxy('catalog')->jsonFields(['tags' => 'list<string>', 'name' => 'string'])
            ->patch('save', '/stock', request: static function (array $form): array|false {
                $row = $form['row'] ?? null;

                return $row instanceof \stdClass ? (array) $row : false;
            });

        $this->request->body = '{"row":{"tags":{},"name":"x"}}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        $this->request->body = '{"row":{"tags":[],"name":9223372036854775808}}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        $this->request->body = '{"row":{"tags":[],"name":"x","private":99}}';
        self::assertSame('{"state":"data"}', $this->dispatch($proxy, 'save', new Response(200, [], '{"data":null}')));
        self::assertCount(1, $this->sent);
        self::assertSame('{"tags":[],"name":"x"}', (string) $this->sent[0]->getBody());
    }

    public function testDeclarativeBodyMapsStillCheckTheFinishedPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $proxy = Strangler::proxy('catalog')
            ->post('save', '/stock', body: ['count' => 'quantity', 'private' => 'raw'])
            ->jsonFields(['count' => 'int']);
        $this->request->body = '{"quantity":"10","raw":"private"}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));

        $this->request->body = '{"quantity":10,"raw":"private"}';
        self::assertSame('{"state":"data"}', $this->dispatch($proxy, 'save', new Response(200, [], '{"data":null}')));
        self::assertCount(1, $this->sent);
        self::assertSame('{"count":10}', (string) $this->sent[0]->getBody());
    }

    public function testNativeGuardsAndBeforeHooksSeeTheOriginalWrite(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $observed = [];
        $proxy = Strangler::proxy('catalog')->jsonFields(['enabled' => 'bool'])
            ->request(
                bypass: static fn (array $payload): bool => isset($payload['native']),
                before: static function (array $payload) use (&$observed): void {
                    $observed[] = $payload;
                },
            )
            ->post('save', '/catalog');

        $this->request->body = '{"native":true,"ENABLED":1}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        $this->request->body = '{"ENABLED":"1"}';
        self::assertFalse($this->dispatch($proxy, 'save', new Response(200)));
        self::assertSame([], $observed);

        $this->request->body = '{"ENABLED":1,"extra":"audit"}';
        self::assertSame('{"state":"data"}', $this->dispatch($proxy, 'save', new Response(200, [], '{"data":null}')));
        self::assertSame([['ENABLED' => 1, 'extra' => 'audit']], $observed);
        self::assertCount(1, $this->sent);
        self::assertSame('{"enabled":true}', (string) $this->sent[0]->getBody());
    }

    #[TestWith([204])]
    #[TestWith([205])]
    public function testNoContentStaysBodyless(int $status): void
    {
        $proxy = Strangler::proxy('catalog')->jsonFields(['name' => 'string'])->get('show', '/items');
        self::assertSame('', $this->dispatch($proxy, 'show', new Response($status)));
        self::assertSame($status, http_response_code());
    }

    /** @param array<string, string> $fields */
    #[TestWith([[]])]
    #[TestWith([['' => 'bool']])]
    #[TestWith([['Label' => 'string', 'label' => 'string']])]
    #[TestWith([['label' => 'str']])]
    public function testInvalidDeclarationFailsAtTheCallSite(array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);
        Strangler::proxy('catalog')->jsonFields($fields);
    }

    private function dispatch(Strangler $declaration, string $action, Response $response): string|false
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->sent[] = $request;
        }));
        $client = new Client(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://catalog.test']);
        $emitter = new ResponseEmitter(function (string $header): void {
            $this->headers[] = $header;
        });

        ob_start();

        try {
            $handled = (new StranglerProxy($client, $emitter))->handle($this->controller, $declaration->build()['config'], $action);

            return $handled ? (string) ob_get_contents() : false;
        } finally {
            ob_end_clean();
        }
    }
}
