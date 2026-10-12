<?php

declare(strict_types=1);

/** Example-only storage: both executors deliberately share one compatible schema. */
function catalogRuntime(): string
{
    $directory = getenv('CATALOG_RUNTIME_DIR') ?: sys_get_temp_dir().'/strangler-catalog';
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Cannot create catalog runtime directory.');
    }

    return $directory;
}

function catalogEvent(string $event, array $details = []): void
{
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    // These local demonstration counters contain no body, credentials or resolved URL.
    file_put_contents(catalogRuntime().'/lifecycle.jsonl', json_encode([
        'event' => $event, 'request_id' => $requestId,
    ] + $details, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

function catalogDatabase(): PDO
{
    $database = new PDO('sqlite:'.catalogRuntime().'/catalog.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, tenant TEXT NOT NULL, owner TEXT NOT NULL, name TEXT NOT NULL, active INTEGER NOT NULL, category TEXT NOT NULL)');
    $database->exec('CREATE TABLE IF NOT EXISTS operations (scope TEXT NOT NULL, operation_key TEXT NOT NULL, fingerprint TEXT NOT NULL, status INTEGER NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY (scope, operation_key))');
    $seed = $database->prepare('INSERT OR IGNORE INTO items VALUES (?, ?, ?, ?, ?, ?)');
    $seed->execute(['9007199254740993', 'alpha', 'alice', 'Desk', 1, '7']);
    $seed->execute(['43', 'alpha', 'bob', 'Private desk', 1, '7']);
    $seed->execute(['44', 'beta', 'bob', 'Beta desk', 0, '8']);

    return $database;
}

function catalogFind(PDO $database, string $id): ?array
{
    $query = $database->prepare('SELECT * FROM items WHERE id = ?');
    $query->execute([$id]);
    $item = $query->fetch(PDO::FETCH_ASSOC);

    return $item === false ? null : $item;
}

function catalogList(PDO $database, string $tenant, string $owner, int $start, int $limit): array
{
    $query = $database->prepare('SELECT * FROM items WHERE tenant = ? AND owner = ? ORDER BY length(id), id LIMIT ? OFFSET ?');
    $query->bindValue(1, $tenant);
    $query->bindValue(2, $owner);
    $query->bindValue(3, $limit, PDO::PARAM_INT);
    $query->bindValue(4, $start, PDO::PARAM_INT);
    $query->execute();
    $count = $database->prepare('SELECT COUNT(*) FROM items WHERE tenant = ? AND owner = ?');
    $count->execute([$tenant, $owner]);

    return ['items' => $query->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
}

function catalogSave(PDO $database, string $tenant, string $owner, array $payload, ?string $id = null): array
{
    if ($id === null) {
        // Decimal-string arithmetic in SQLite preserves IDs that JavaScript numbers cannot.
        $id = (string) $database->query('SELECT MAX(CAST(id AS INTEGER)) + 1 FROM items')->fetchColumn();
        $query = $database->prepare('INSERT INTO items VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([$id, $tenant, $owner, $payload['NAME'], (int) $payload['ACTIVE'], $payload['CATEGORY_ID']]);
    } else {
        $query = $database->prepare('UPDATE items SET name = ?, active = ?, category = ? WHERE id = ? AND tenant = ? AND owner = ?');
        $query->execute([$payload['NAME'], (int) $payload['ACTIVE'], $payload['CATEGORY_ID'], $id, $tenant, $owner]);
    }
    catalogEvent('write', ['executor' => defined('CATALOG_EXECUTOR') ? CATALOG_EXECUTOR : 'unknown']);

    return catalogFind($database, $id) ?? throw new RuntimeException('Saved item not found.');
}

function catalogLegacyItem(array $item): array
{
    return ['ID' => (string) $item['id'], 'NAME' => $item['name'], 'ACTIVE' => (bool) $item['active'], 'CATEGORY_ID' => (string) $item['category']];
}

function catalogBackendItem(array $item): array
{
    return ['id' => (string) $item['id'], 'product' => ['title' => $item['name'], 'enabled' => (bool) $item['active']], 'category' => ['id' => (string) $item['category']]];
}

function catalogJson(int $status, array $body): void
{
    header('Content-Type: application/json; charset=utf-8', true, $status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
}

function catalogErrorBody(string $code, array $fields = []): array
{
    return ['success' => false, 'error' => ['code' => $code, 'fields' => $fields === [] ? new stdClass() : $fields]];
}
