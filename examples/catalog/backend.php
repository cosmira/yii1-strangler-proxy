<?php

declare(strict_types=1);

define('CATALOG_EXECUTOR', 'backend');
require __DIR__.'/common.php';

$token = getenv('STRANGLER_TOKEN') ?: 'local-demo-token';
if (! hash_equals($token, $_SERVER['HTTP_X_STRANGLER_TOKEN'] ?? '')) {
    catalogJson(403, ['code' => 'untrusted_caller']);

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') {
    catalogJson(200, ['ready' => true]);

    return;
}
$tenant = $_SERVER['HTTP_X_TENANT_ID'] ?? '';
$user = $_SERVER['HTTP_X_USER_ID'] ?? '';
if ($tenant === '' || $user === '') {
    catalogJson(403, ['code' => 'trusted_context_required']);

    return;
}
$method = $_SERVER['REQUEST_METHOD'];
catalogEvent('backend', ['method' => $method]);
$mode = getenv('CATALOG_BACKEND_MODE') ?: 'normal';
if ($method === 'GET' && $mode === 'malformed') {
    header('Content-Type: text/html');
    echo '<html>Invalid backend response</html>';

    return;
}
if ($method === 'GET' && in_array($mode, ['empty_object', 'empty_array'], true)) {
    catalogJson(200, ['data' => $mode === 'empty_object' ? new stdClass() : []]);

    return;
}
$database = catalogDatabase();
$id = preg_match('~^/api/items/([0-9]+)$~', (string) $path, $matches) === 1 ? $matches[1] : null;
if ($path !== '/api/items' && $id === null) {
    catalogJson(404, ['code' => 'route_not_found']);

    return;
}
if ($id !== null) {
    $item = catalogFind($database, $id);
    if ($item === null || $item['tenant'] !== $tenant || $item['owner'] !== $user) {
        catalogJson(404, ['code' => 'item_not_found']);

        return;
    }
}
if ($method === 'GET') {
    if ($id !== null) {
        catalogJson(200, ['data' => catalogBackendItem($item)]);
    } else {
        $limit = max(1, (int) ($_GET['per_page'] ?? 20));
        $start = (max(1, (int) ($_GET['page'] ?? 1)) - 1) * $limit;
        $result = catalogList($database, $tenant, $user, $start, $limit);
        catalogJson(200, ['data' => array_map('catalogBackendItem', $result['items']), 'total' => $result['total']]);
    }

    return;
}
if (($method !== 'POST' || $id !== null) && ($method !== 'PUT' || $id === null)) {
    catalogJson(405, ['code' => 'method_not_allowed']);

    return;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException) {
    catalogJson(400, ['code' => 'invalid_payload']);

    return;
}
if (! is_array($payload) || ! isset($payload['product']['title'], $payload['product']['enabled'], $payload['category']['id'])) {
    catalogJson(400, ['code' => 'invalid_payload']);

    return;
}
if ($mode === 'validation' || trim($payload['product']['title']) === '') {
    $errors = $mode === 'validation'
        ? ['product.title' => ['Required'], 'title' => ['Too short'], 'category.id' => ['Invalid category'], 'product.enabled' => ['Invalid state'], 'internal_field' => ['Cannot process item']]
        : ['product.title' => ['Required']];
    catalogJson(422, ['code' => 'validation_failed', 'errors' => $errors]);

    return;
}
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if (strlen($key) > 128) {
    catalogJson(400, ['code' => 'invalid_idempotency_key']);

    return;
}
// Scope, body fingerprint, persisted outcome and 24h retention are backend contracts.
$scope = json_encode([$tenant, $user, $method, $path], JSON_THROW_ON_ERROR);
$fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
$database->beginTransaction();
$database->exec('DELETE FROM operations WHERE created_at < '.(time() - 86400));
if ($key !== '') {
    $query = $database->prepare('SELECT * FROM operations WHERE scope = ? AND operation_key = ?');
    $query->execute([$scope, $key]);
    $operation = $query->fetch(PDO::FETCH_ASSOC);
    if ($operation !== false) {
        $database->commit();
        if (! hash_equals($operation['fingerprint'], $fingerprint)) {
            catalogJson(409, ['code' => 'idempotency_conflict']);
        } else {
            header('X-Idempotency-Replayed: 1');
            catalogJson((int) $operation['status'], json_decode($operation['body'], true, 512, JSON_THROW_ON_ERROR));
        }

        return;
    }
}
$legacy = ['NAME' => $payload['product']['title'], 'ACTIVE' => $payload['product']['enabled'], 'CATEGORY_ID' => (string) $payload['category']['id']];
$saved = catalogSave($database, $tenant, $user, $legacy, $id);
$status = $id === null ? 201 : 200;
$body = ['data' => catalogBackendItem($saved)];
if ($key !== '') {
    $query = $database->prepare('INSERT INTO operations VALUES (?, ?, ?, ?, ?, ?)');
    $query->execute([$scope, $key, $fingerprint, $status, json_encode($body, JSON_THROW_ON_ERROR), time()]);
}
$database->commit();
if ($mode === 'lost_response') {
    // The write and idempotency outcome are committed before a client can time out.
    usleep((int) ((float) (getenv('CATALOG_FAULT_DELAY') ?: '2') * 1000000));
}
catalogJson($status, $body);
