<?php

declare(strict_types=1);

use GuzzleHttp\Client;

return static function (CatalogContracts $verify, Client $client, string $backend, string $directory, bool $reads, bool $writes): void {
    $sequence = 0;
    $events = static function () use ($directory): array {
        $path = $directory.'/lifecycle.jsonl';
        if (! is_file($path)) {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    };
    $request = static function (string $method, string $route, array $options = []) use ($verify, $client, &$sequence, $events): array {
        $id = 'contract-'.++$sequence;
        $options['headers']['X-Request-Id'] = $id;
        $response = $client->request($method, '/?r='.$route, $options);
        $body = $verify->json($response);
        $requestEvents = array_values(array_filter($events(), static fn (array $event): bool => ($event['request_id'] ?? null) === $id));
        $cleanup = array_filter($requestEvents, static fn (array $event): bool => ($event['event'] ?? null) === 'cleanup');
        $verify->check(count($cleanup) === 1, $route.' cleanup exactly once');

        return [$response, $body, $requestEvents, $id];
    };
    $status = static function (int $expected, array $result, string $name) use ($verify): void {
        if ($result[0]->getStatusCode() !== $expected) {
            throw new RuntimeException('FAIL '.$name.' expected '.$expected.', got '.$result[0]->getStatusCode().': '.(string) $result[0]->getBody());
        }
        $verify->check(true, $name.' status '.$expected);
        $verify->check(str_starts_with($result[0]->getHeaderLine('Content-Type'), 'application/json'), $name.' JSON content type');
        if ($expected >= 400) {
            $object = json_decode((string) $result[0]->getBody(), flags: JSON_THROW_ON_ERROR);
            $verify->check($object->success === false && $object->error->fields instanceof stdClass, $name.' error fields retain object shape');
        }
    };
    $noExecution = static function (array $result, string $name) use ($verify): void {
        $execution = array_filter($result[2], static fn (array $event): bool => in_array($event['event'] ?? '', ['legacy', 'backend', 'write'], true));
        $verify->check($execution === [], $name.' rejected before either executor');
    };
    $result = $request('GET', 'catalog/get');
    $status(401, $result, 'guest');
    $noExecution($result, 'guest');
    $login = $request('POST', 'session/login', ['json' => ['user' => 'alice']]);
    $status(200, $login, 'session login');
    $csrf = $login[1]['csrf'];
    $headers = ['X-CSRF-Token' => $csrf];
    $item = ['NAME' => 'Contract desk', 'ACTIVE' => true, 'CATEGORY_ID' => '7'];
    foreach ([
        ['GET', 'catalog/create', [], 405, 'wrong incoming verb'],
        ['POST', 'catalog/create', ['json' => $item], 403, 'missing CSRF'],
        ['POST', 'catalog/create', ['json' => $item, 'headers' => ['X-CSRF-Token' => 'wrong']], 403, 'invalid CSRF'],
        ['POST', 'catalog/update&id=9007199254740993', ['json' => ['ID' => '42'] + $item, 'headers' => $headers], 400, 'conflicting identifiers'],
        ['POST', 'catalogForm/update', ['form_params' => ['id' => '43', 'item' => ['ID' => '9007199254740993'] + $item], 'headers' => $headers], 400, 'top-level form identifier conflict'],
        ['POST', 'catalog/create', ['body' => '{bad', 'headers' => $headers + ['Content-Type' => 'application/json']], 400, 'malformed JSON'],
        ['POST', 'catalogForm/create', ['form_params' => ['item' => 'wrong'], 'headers' => $headers], 400, 'invalid form shape'],
        ['GET', 'catalog/view&id=43', [], 403, 'another owner denied'],
        ['GET', 'catalog/view&id=44', [], 403, 'another tenant denied'],
    ] as [$method, $route, $options, $expected, $name]) {
        $result = $request($method, $route, $options);
        $status($expected, $result, $name);
        $noExecution($result, $name);
    }
    $result = $request('GET', 'catalog/view&id=9007199254740993', [
        'headers' => ['X-Tenant-Id' => 'beta', 'X-User-Id' => 'bob', 'X-Strangler-Token' => 'spoof'],
    ]);
    $status(200, $result, 'tenant identity uses server session');
    $verify->check($result[1]['item']['ID'] === '9007199254740993', 'identifier remains an exact string');
    $verify->check($result[1]['item']['ACTIVE'] === true, 'boolean contract retained');
    $nodePipes = [];
    $node = @proc_open(['node', '-e', 'const item = JSON.parse(process.argv[1]).item; if (typeof item.ID !== "string" || item.ID !== "9007199254740993") process.exit(1);', (string) $result[0]->getBody()],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes);
    if (is_resource($node)) {
        fclose($nodePipes[0]);
        $nodeError = stream_get_contents($nodePipes[2]);
        fclose($nodePipes[1]);
        fclose($nodePipes[2]);
        $nodeStatus = proc_close($node);
        if ($nodeStatus === 127) {
            echo "  SKIP JavaScript decoder: install Node.js to verify the browser number boundary.\n";
            $verify->check(getenv('CATALOG_REQUIRE_NODE') !== '1', 'required JavaScript decoder available');
        } else {
            $verify->check($nodeStatus === 0, 'real JavaScript decoder retains ID '.$nodeError);
        }
    } else {
        echo "  SKIP JavaScript decoder: Node.js unavailable.\n";
        $verify->check(getenv('CATALOG_REQUIRE_NODE') !== '1', 'required JavaScript decoder available');
    }
    $executor = array_filter($result[2], static fn (array $event): bool => ($event['event'] ?? '') === ($reads ? 'backend' : 'legacy'));
    $verify->check(count($executor) === 1, 'read flag chooses one executor');
    $rejectedTitle = $request('POST', 'catalog/create', ['json' => ['NAME' => ''] + $item, 'headers' => $headers]);
    $status(422, $rejectedTitle, 'business validation with either executor');
    $verify->check(($rejectedTitle[1]['error']['fields']['NAME'] ?? null) === ['Required'], 'business validation targets the client field NAME');
    $direct = (new Client(['http_errors' => false]))->get($backend.'/health');
    $verify->check($direct->getStatusCode() === 403, 'direct backend without service token denied');

    $created = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $headers]);
    $status(201, $created, 'create JSON');
    $createdId = $created[1]['item']['ID'];
    $verify->check(is_string($createdId), 'created identifier type');
    $verify->check($created[1]['item']['NAME'] === $item['NAME'], 'created legacy fields');
    $executor = array_filter($created[2], static fn (array $event): bool => ($event['event'] ?? '') === ($writes ? 'backend' : 'legacy'));
    $verify->check(count($executor) === 1, 'write flag chooses one executor');
    $verify->check(count(array_filter($created[2], static fn (array $event): bool => ($event['event'] ?? '') === 'write')) === 1, 'creation writes exactly once');
    $formItem = ['NAME' => 'Form desk', 'ACTIVE' => '1', 'CATEGORY_ID' => '7'];
    $form = $request('POST', 'catalogForm/create', ['form_params' => ['item' => $formItem], 'headers' => $headers]);
    $status(201, $form, 'create form');
    $verify->check($form[1]['item']['ACTIVE'] === true && $form[1]['item']['CATEGORY_ID'] === '7', 'form normalized types');
    $updated = $request('POST', 'catalog/update&id='.$createdId, ['json' => ['ID' => $createdId, 'NAME' => 'Updated desk'] + $item, 'headers' => $headers]);
    $status(200, $updated, 'update JSON through incoming POST');
    $verify->check($updated[1]['item']['NAME'] === 'Updated desk', 'update uses legacy field names');
    if ($writes) {
        $upstreamUpdates = array_values(array_filter($updated[2], static fn (array $event): bool => ($event['event'] ?? '') === 'backend'));
        $verify->check(count($upstreamUpdates) === 1 && $upstreamUpdates[0]['method'] === 'PUT', 'incoming POST maps to one upstream PUT');
    }
    $formUpdate = $request('POST', 'catalogForm/update&id='.$createdId, ['form_params' => ['item' => ['ACTIVE' => '0', 'NAME' => 'Updated form desk'] + $formItem], 'headers' => $headers]);
    $status(200, $formUpdate, 'update form');
    $verify->check($formUpdate[1]['item']['ACTIVE'] === false, 'form zero retains boolean false');
    foreach (['catalog/get&start=0&limit=20', 'catalog/get&start=1&limit=20'] as $route) {
        $list = $request('GET', $route);
        $status(200, $list, 'read after write '.$route);
        $ids = array_column($list[1]['items'], 'ID');
        $verify->check(in_array($createdId, $ids, true), 'created record visible '.$route);
        if (str_contains($route, 'start=1&')) {
            $verify->check(count(array_filter($list[2], static fn (array $event): bool => ($event['event'] ?? '') === 'legacy')) === 1
                && count(array_filter($list[2], static fn (array $event): bool => ($event['event'] ?? '') === 'backend')) === 0, 'pagination bypass chooses Yii before transport');
            $logs = file_get_contents($directory.'/strangler.log');
            $lines = array_filter(explode("\n", $logs), static fn (string $line): bool => str_contains($line, 'request_id: '.$list[3]));
            $correlated = implode("\n", $lines);
            $verify->check(str_contains($correlated, $reads ? 'reason: bypass_callback' : 'reason: feature_disabled'), 'published log explains candidate by request ID');
            $verify->check(! str_contains($correlated, 'catalog-contract-token') && ! str_contains($correlated, 'start=1'), 'published diagnostic omits secrets and query');
        }
    }
    $empty = $request('GET', 'catalog/get&start=1000&limit=20');
    $status(200, $empty, 'empty list');
    $verify->check($empty[1]['items'] === [] && is_array(json_decode((string) $empty[0]->getBody(), flags: JSON_THROW_ON_ERROR)->items), 'empty list stays JSON array');
    $verify->configure(['CATALOG_READ_ENABLED' => 'false', 'CATALOG_WRITE_ENABLED' => 'false']);
    $rolledBack = $request('GET', 'catalog/view&id='.$createdId);
    $status(200, $rolledBack, 'flag off reads existing backend-created data');
    $verify->configure(['CATALOG_READ_ENABLED' => $reads ? 'true' : 'false', 'CATALOG_WRITE_ENABLED' => $writes ? 'true' : 'false']);
    $cases = static function () use ($verify, $request, $status, $headers, $item): void {
        $verify->configure(['CATALOG_BACKEND_MODE' => 'malformed']);
        $malformed = $request('GET', 'catalog/view&id=9007199254740993');
        $status(500, $malformed, 'malformed backend returns JSON error');
        $verify->check(($malformed[1]['success'] ?? true) === false, 'malformed response error envelope');
        $verify->check(count(array_filter($malformed[2], static fn (array $event): bool => ($event['event'] ?? '') === 'backend')) === 1
            && count(array_filter($malformed[2], static fn (array $event): bool => ($event['event'] ?? '') === 'legacy')) === 0, 'malformed backend never replays in Yii');
        foreach (['empty_object', 'empty_array'] as $mode) {
            $verify->configure(['CATALOG_BACKEND_MODE' => $mode]);
            $emptyItem = $request('GET', 'catalog/view&id=9007199254740993');
            $status(500, $emptyItem, $mode.' is not a valid catalog item');
        }
        $verify->configure(['CATALOG_BACKEND_MODE' => 'validation']);
        $validation = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $headers]);
        $status(422, $validation, 'backend validation');
        $fields = $validation[1]['error']['fields'];
        $verify->check(isset($fields['NAME'], $fields['CATEGORY_ID'], $fields['ACTIVE'], $fields['_general']), 'validation fields mapped to legacy names including unknown fields');
        $verify->configure(['CATALOG_BACKEND_MODE' => 'normal', 'CATALOG_METRICS_FAIL' => '1']);
        $metrics = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $headers]);
        $status(201, $metrics, 'metrics exception preserves successful write response');
        $verify->configure(['CATALOG_METRICS_FAIL' => '0', 'CATALOG_HOOK_FAIL' => '1']);
        $hook = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $headers]);
        $status(500, $hook, 'mandatory hook failure visible');
        $verify->check(count(array_filter($hook[2], static fn (array $event): bool => ($event['event'] ?? '') === 'write')) === 1
            && count(array_filter($hook[2], static fn (array $event): bool => ($event['event'] ?? '') === 'legacy')) === 0, 'mandatory hook does not replay successful write');
        $verify->configure(['CATALOG_HOOK_FAIL' => '0']);
        $verify->configure(['CATALOG_BACKEND_MODE' => 'lost_response', 'CATALOG_TIMEOUT' => '1']);
        $keyedHeaders = $headers + ['Idempotency-Key' => 'explicit-client-retry'];
        $lost = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $keyedHeaders]);
        $status(503, $lost, 'lost response after committed write');
        $verify->check(count(array_filter($lost[2], static fn (array $event): bool => ($event['event'] ?? '') === 'write')) === 1
            && count(array_filter($lost[2], static fn (array $event): bool => ($event['event'] ?? '') === 'backend')) === 1
            && count(array_filter($lost[2], static fn (array $event): bool => ($event['event'] ?? '') === 'legacy')) === 0, 'timeout commits once without automatic retry or fallback');
        $verify->configure(['CATALOG_BACKEND_MODE' => 'normal', 'CATALOG_TIMEOUT' => '2']);
        $repeat = $request('POST', 'catalog/create', ['json' => $item, 'headers' => $keyedHeaders]);
        $status(201, $repeat, 'explicit same-key retry returns stored outcome');
        $verify->check(count(array_filter($repeat[2], static fn (array $event): bool => ($event['event'] ?? '') === 'write')) === 0, 'same-key retry creates no second record');
        $conflict = $request('POST', 'catalog/create', ['json' => ['NAME' => 'Different request'] + $item, 'headers' => $keyedHeaders]);
        $status(409, $conflict, 'same key different body conflicts');
        $verify->check($conflict[1]['error']['code'] === 'idempotency_conflict'
            && count(array_filter($conflict[2], static fn (array $event): bool => ($event['event'] ?? '') === 'write')) === 0, 'idempotency conflict cannot write');
    };
    if ($reads && $writes) {
        $cases();
    }
};
