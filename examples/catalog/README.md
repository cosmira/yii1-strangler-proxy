# Run the catalog example

This example runs a real Yii 1 application and a small backend with the public
Strangler builder. It demonstrates one compatible catalog contract, including a
legacy POST that becomes an upstream PUT. It requires PHP 8.2+, Composer and
`pdo_sqlite`. Node.js is optional for the verifier's JavaScript JSON decoder case.

From the repository root:

```sh
composer install
export CATALOG_RUNTIME_DIR="$(mktemp -d)"
export STRANGLER_TOKEN=local-demo-token
export CATALOG_BACKEND_URL=http://127.0.0.1:9082
export CATALOG_READ_ENABLED=false
export CATALOG_WRITE_ENABLED=false
php examples/catalog/check.php
export CATALOG_READ_ENABLED=true
php -S 127.0.0.1:9082 examples/catalog/backend.php \
  > "$CATALOG_RUNTIME_DIR/backend-server.log" 2>&1 &
CATALOG_BACKEND_PID=$!
php -S 127.0.0.1:9081 examples/catalog/legacy.php \
  > "$CATALOG_RUNTIME_DIR/legacy-server.log" 2>&1 &
CATALOG_LEGACY_PID=$!
curl --fail --retry 5 --retry-connrefused --retry-delay 1 -sS \
  -H "X-Strangler-Token: $STRANGLER_TOKEN" "$CATALOG_BACKEND_URL/health"
curl --retry 5 --retry-connrefused --retry-delay 1 -sS -o /dev/null \
  'http://127.0.0.1:9081/?r=session/login'
```

Both processes now run in the same terminal's environment. Keep both listeners on
loopback. Ports are your choice: change the `php -S`
addresses, `CATALOG_BACKEND_URL`, and the URLs below together. Store runtime files
in a temporary directory, never in the checkout. Both processes must use the same
runtime directory and service token.
The two final commands wait for listeners before the first request. The Yii GET
reaches the login endpoint without signing in; its expected method error is only a
listener check. If startup fails, inspect the two server logs before continuing.

## Make the first request

The demo accepts only `alice` and `bob`. Its local login has no password and is
deliberately unsuitable for production. Alice owns tenant `alpha`; Bob owns tenant
`beta`. Tenant membership is a server-owned identity map, not a supplied header.

```sh
curl -sS -c "$CATALOG_RUNTIME_DIR/cookies.txt" \
  -H 'Content-Type: application/json' \
  -d '{"user":"alice"}' \
  'http://127.0.0.1:9081/?r=session/login'
curl -i -b "$CATALOG_RUNTIME_DIR/cookies.txt" \
  'http://127.0.0.1:9081/?r=catalog/view&id=9007199254740993'
```

The login returns `csrf`, `user_id` and `tenant`. The view returns:

```json
{"success":true,"item":{"ID":"9007199254740993","NAME":"Desk","ACTIVE":true,"CATEGORY_ID":"7"}}
```

With reads enabled, it also has `X-Strangler: 1`. To run the same request in the
legacy action, reload the flag by restarting only the Yii process:

```sh
kill "$CATALOG_LEGACY_PID"
wait "$CATALOG_LEGACY_PID" 2>/dev/null || true
export CATALOG_READ_ENABLED=false
php -S 127.0.0.1:9081 examples/catalog/legacy.php \
  > "$CATALOG_RUNTIME_DIR/legacy-server.log" 2>&1 &
CATALOG_LEGACY_PID=$!
curl --retry 5 --retry-connrefused --retry-delay 1 -sS -o /dev/null \
  'http://127.0.0.1:9081/?r=session/login'
```

The session and stored item survive a restart in the same runtime directory. Use
the same procedure to change `CATALOG_WRITE_ENABLED` to `true` when ready.

## Create and update through JSON or forms

Copy the login's `csrf` into a shell variable. This token belongs to the Yii session;
it is checked before either executor and is not forwarded to the backend.

```sh
export CATALOG_CSRF='paste-the-csrf-from-login'
curl -i -b "$CATALOG_RUNTIME_DIR/cookies.txt" \
  -H "X-CSRF-Token: $CATALOG_CSRF" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: first-desk' \
  -d '{"NAME":"New desk","ACTIVE":true,"CATEGORY_ID":"7"}' \
  'http://127.0.0.1:9081/?r=catalog/create'
curl -i -b "$CATALOG_RUNTIME_DIR/cookies.txt" \
  -H "X-CSRF-Token: $CATALOG_CSRF" \
  --data-urlencode 'item[NAME]=Form desk' --data-urlencode 'item[ACTIVE]=1' \
  --data-urlencode 'item[CATEGORY_ID]=7' \
  'http://127.0.0.1:9081/?r=catalogForm/create'
curl -i -b "$CATALOG_RUNTIME_DIR/cookies.txt" \
  -H "X-CSRF-Token: $CATALOG_CSRF" -H 'Content-Type: application/json' \
  -d '{"ID":"9007199254740993","NAME":"Updated desk","ACTIVE":true,"CATEGORY_ID":"7"}' \
  'http://127.0.0.1:9081/?r=catalog/update'
```

Create returns `201`; update returns `200`. Both use the legacy item envelope.
`catalog` explicitly accepts JSON; `catalogForm` explicitly accepts `item[...]`.
The host validation filter parses once and stores `validatedInput`; both the
legacy action and `payloadUsing()` consume that same checked value. Invalid form
structure, malformed JSON, missing fields and conflicting query/body IDs return
`400`. Incoming writes must use POST (`405` otherwise); upstream update uses PUT.
Empty names return `422` with errors under `NAME`. Backend aliases such as
`product.title` and `title` merge into `NAME`; unknown field messages are preserved
under `_general` without exposing backend field names.

## Filters and lifecycle

The host runs authentication, input/method validation, CSRF, resource access and
trusted-context filters before the entire Strangler block. Unauthenticated requests
return `401`; wrong CSRF and another owner's or tenant's item return `403`. The
seed includes inaccessible items `43` and `44` for those checks. The JSON error
handler also covers adapter failures and malformed backend responses with `500`
and no internal details.

| Existing application code | Integration location |
| --- | --- |
| Access, verb or input checks in action / `beforeAction()` | Existing pre-filters, before both Strangler filters |
| Resource permissions | Pre-filter using the exact validated identifier |
| Cleanup in `afterAction()` / earlier `postFilter()` | Tested request-end mechanism, here `onEndRequest` |
| Optional metrics | Local `try/catch` inside an application callback |

The host's `onEndRequest` records one `cleanup` event in `lifecycle.jsonl` for
legacy, proxied and denied requests. `X-Cleanup-Count: 1` makes it visible in this
buffered demo. Strangler ends proxied requests, so `afterAction()` and earlier
filters' `postFilter()` cannot provide that cleanup. Neither host nor backend keeps
an open database transaction across the HTTP call.

`proxy.php` contains reusable, side-effect-free builder definitions and settings;
`controllers.php` contains application-owned security and input handling;
`CatalogApiAdapter.php` translates the client contract; `backend.php` owns the new
API and idempotency; `common.php` describes the shared demonstration schema.

## Rollout, data and bypass

| Read flag | Write flag | Read executor | Write executor |
| --- | --- | --- | --- |
| false | false | Yii | Yii |
| true | false | Backend | Yii |
| false | true | Yii | Backend |
| true | true | Backend | Backend |

`catalog_read` and `catalog_write` have disjoint actions. Overlapping mappings would
make the earlier enabled filter win. Flags are parsed as booleans, then loaded at
each request in this sample; restarting the server after changing its environment
demonstrates reload. A request already started keeps its selected executor.

Both executors read and write **one SQLite schema**. Exactly one executor performs
each write. This makes backend creation visible to backend listing, legacy listing
and flag-off viewing. The legacy `start`/`limit` pagination cannot always be converted
to backend pages: `start=1&limit=20` bypasses before any HTTP call and reads the same
data in Yii. Such bypass is safe here because the shared schema and permissions are
compatible. Another deployment needs its own compatible read source and data plan.
Turning off flags changes routing; it does not reverse data changes or restore a
schema. Do not promise rollback after incompatible backend writes.

## Trust and idempotency

The backend rejects calls without the server token. A preceding host filter takes
the tenant from the authorized session and sets a server-owned `X-Tenant-Id` in
the per-request configuration. Client attempts to spoof tenant, user or service
token do not change that context. The backend independently limits reads and writes
to its trusted tenant/user. Missing identity or tenant returns `403` there; guest
requests return `401` in Yii. Production must use real authentication, HTTPS and
secret distribution instead of this local login and demo token.

With writes enabled, Strangler forwards the client's `Idempotency-Key`. The backend
scopes it by tenant, user, outgoing method and path; fingerprints the translated
body; and commits the saved response and write together. An identical retry returns
the persisted response without another write. A changed body with the same key
returns `409 idempotency_conflict`. Records expire after 24 hours. The legacy demo
does not deduplicate requests: during rollout, keys provide this guarantee only
when the selected executor is the backend. The proxy neither generates keys nor
retries requests. Keep routing stable while investigating an uncertain write.

## Verify and investigate

```sh
php examples/catalog/verify.php
php examples/catalog/check.php
```

The configuration check uses the host's actual declarations, including with both
flags off. It checks origin, routes, adapter class/interface, callback declarations,
headers, TLS and timeouts without constructing the adapter, invoking callbacks,
creating storage or contacting the backend. Missing `STRANGLER_TOKEN` fails the
check even though the local runtime has a demo fallback. Invalid timeouts report
the runtime fallback as warnings. It does not validate business adapter constructors.

Check reachability separately, only when desired and after starting the backend:

```sh
curl --fail -sS -H "X-Strangler-Token: $STRANGLER_TOKEN" \
  "$CATALOG_BACKEND_URL/health"
```

This read-only endpoint checks the trusted channel; it does not access catalog
storage or prove a future write will succeed. Never probe a write route.

The verifier starts isolated local servers and temporary stores, checks all four
flag combinations and exercises the published source files. It checks legacy
fields/types, exact string IDs, read-after-write, access/input failures, cleanup,
backend faults and idempotent recovery. With Node.js installed it also decodes the
response using actual JavaScript; `CATALOG_REQUIRE_NODE=1` requires that case.

Supply `X-Request-Id` to associate responses, `strangler.log` and `lifecycle.jsonl`.
Counters name `legacy`, `backend`, `write`, `metrics`, `metrics_failed` and `cleanup`.
The example writes no request body or token to those counters. Logs explain each
feature candidate, so a skipped read filter does not imply the later write filter
was skipped too. Consult the [operations guide](../../docs/operations.md) for
deployment checks and uncertain-write recovery.

Server-side development profiles are explicit environment settings, not request
parameters. Restart the relevant process with `CATALOG_BACKEND_MODE=malformed`,
`validation`, `empty_object`, `empty_array` or `lost_response` to reproduce faults.
For the last case the backend commits before waiting `CATALOG_FAULT_DELAY` seconds
(default 2); the host times out after `CATALOG_TIMEOUT` (default 1) and returns `503`.
Restore `normal`, then explicitly repeat with the original key and body to recover
the saved result. Do not replay in Yii. `CATALOG_METRICS_FAIL=1` demonstrates safe
optional observation; `CATALOG_HOOK_FAIL=1` demonstrates the unchanged mandatory
hook contract, which reaches the host error handler after a backend attempt.

Stop both servers and remove the temporary directory to reset everything:

```sh
kill "$CATALOG_LEGACY_PID" "$CATALOG_BACKEND_PID"
wait "$CATALOG_LEGACY_PID" "$CATALOG_BACKEND_PID" 2>/dev/null || true
rm -rf "$CATALOG_RUNTIME_DIR"
```

No generated SQLite, cookies, session files or logs belong in version control.
