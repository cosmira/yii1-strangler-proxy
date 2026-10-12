# Operating a migrated feature

Use this runbook with the [integration guide](integration.md) and the
[runnable catalog](../examples/catalog/README.md). The host application owns its
deployment, authentication, data and backend contract. Strangler chooses an executor;
it does not coordinate transactions between systems.

## Record the data contract first

Fill this table before enabling writes. A boolean flag cannot establish data compatibility.

| Decision | Catalog example | Your application must decide |
| --- | --- | --- |
| Write owner | One selected executor writes the shared dataset. | Which system owns each dataset and operation? |
| Yii reads | Same compatible SQLite dataset, tenant-scoped. | Will Yii see new writes and understand their schema? |
| Backend reads | Same compatible dataset, tenant-scoped. | What consistency and read-after-write guarantees exist? |
| Bypass | Legacy list handles non-page-aligned offsets. | Which variants may remain in Yii without stale or conflicting data? |
| Routing rollback | Allowed while old readers/writers remain schema-compatible. | Which changes make turning the flag off unsafe? |
| In-flight requests | Continue with their already selected executor. | How are their eventual outcomes checked during rollback? |

The shared database is one concrete demonstration. Independent stores require a
separate synchronization and ownership plan; do not infer that the example proves
their compatibility. Do not add dual writes to a callback to emulate a transaction.

## Before enabling

1. Run the application's configuration check while flags remain off. Check origin,
   route definitions, adapter class/interface, callback declarations, header names,
   TLS and timeout values. Show defaulted invalid timeouts as warnings.
2. Keep secrets in the deployment environment and verify their presence without
   printing values. Configuration checks do not invoke readers, business adapters,
   hooks, write endpoints or legacy actions.
3. If desired, call the backend's explicitly documented read-only readiness endpoint.
   Treat reachability and configuration validation as separate results. A successful
   probe does not guarantee a later write or its API contract.
4. Verify direct backend calls without the trusted token are rejected. Verify guest,
   wrong-resource, wrong-tenant, CSRF and incoming-method rejection in the real host.
5. Run the consumer contract cases against both executors on isolated identical data.
   Include successful writes, field errors, large string IDs and read-after-write.
6. Enable the read flag first if the data contract allows it, then verify requests and
   the observed statuses. Enable the write flag separately after its cases pass.

The two flags use existing builders. Put the complete Strangler filter block after
all security checks and keep its action maps disjoint. A skipped read candidate may
continue to the write candidate; it has not yet chosen the legacy executor.

## Troubleshooting one request

Enable `params.strangler.log_requests` deliberately and collect the Yii `strangler`
category. The default avoids a log entry for every normal request.
Use a valid `X-Request-Id` to correlate the host and backend without printing bodies,
credentials, query strings or resolved resource identifiers.

| Diagnostic result | Check next |
| --- | --- |
| `feature_disabled` | Exact boolean flag and the host's configuration reload. |
| `route_unmapped` | Lowercase action name and the map of this candidate filter. |
| `bypass_callback` | Application rule and the original request variant. |
| `bypass_payload` | Presence of the original payload field, including a null value. |
| `forwarded` | Selected candidate, method, route template and backend correlation. |
| Backend/client statuses differ | Adapter's explicit status mapping and client error contract. |
| Transport failure | Backend/network telemetry and the operation's possible outcome. |
| Hook or adapter failure | Application code and error handler; never compensate by replaying writes. |

Candidate diagnostics describe an evaluated filter, not a final claim that Yii ran.
With several filters, inspect the complete request ID sequence. Confirm final legacy
execution using the host's own action telemetry. The example checks this composition.
Keep raw exception details out of shared diagnostic logs: HTTP client messages may
contain a full URI. Use protected host/backend tooling for any deeper investigation.

## A write timed out

A `503` says the proxy did not obtain the expected transport response. It does not
prove that the backend failed to commit the operation.

1. Preserve the original request ID and, if the backend supports it, the original
   idempotency key. Do not create a new key just to repeat an uncertain write.
2. Consult the backend's documented operation-status or idempotency lookup. Search
   by the appropriate tenant/user/operation scope; the package provides no lookup API.
3. If the operation completed, reconcile its recorded result with the client.
4. If a client retry is permitted by the backend contract, repeat explicitly using
   the same key and equivalent body. A changed body must produce a conflict.
5. If the outcome cannot be established and no idempotency contract exists, escalate
   for reconciliation. Do not replay automatically in either Yii or the backend.

The example persists an idempotency result at the backend. Its verifier demonstrates
an explicit repeat after a lost response and checks that only one object exists.
This is backend behavior, not proxy-side deduplication or retry.
Production must also define retention, concurrency and recovery for its own storage.

## Disable and verify

1. Check the recorded data contract: can the old executor safely read and write the
   new data? If not, stop and use the separate data recovery plan.
2. Disable only the necessary read or write flag using the host's deployment process.
3. Reload cached configuration or long-running workers as your host requires.
4. Send a new permitted request with a new request ID. Confirm the actual executor
   and client contract, rather than inferring success from the deployment command.
5. Reconcile writes that were in flight. They continue under their earlier selection;
   flipping a flag does not cancel them, undo data or restore an old schema.

Never fall back to Yii after forwarding. The backend may have completed a write even
when transport, callback or response adaptation subsequently failed.

## Tenant and idempotency boundaries

Derive tenant context in the host after session authentication and resource checks.
Set it as a server-owned header; do not put the tenant header into the incoming
client allowlist. The backend authenticates the channel before trusting that context
and still enforces tenant boundaries and business permissions.
An identical user ID in two tenants must not expose a shared unscoped dataset.
Define guest behavior and reject missing required context rather than guessing it.

The backend defines the idempotency key scope, request fingerprint, stored result,
conflict policy and retention. Strangler forwards the existing key; it does not create
a new one or declare the operation exactly-once. The example's contract cases cover
tenant spoofing, repeated keys and a conflicting body.

## Optional metrics

Use the existing request logs for ordinary statuses and timings. If an application
adds an optional metrics callback, catch and safely log that sink's failure inside
the callback. It should not turn a confirmed backend write into a client error.
Required hooks retain their existing exception contract. Do not silently change
them into best-effort behavior or use metrics callbacks for essential business work.
