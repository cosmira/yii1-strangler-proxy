# Upgrade to 4.0

Version 4.0 keeps resource-specific response callbacks on their owning resource.
It also adds reusable controller-backed relations and declarative request,
selection and operation-result rules. No additional application builder is needed.

```shell
composer require cosmira/yii1-strangler-proxy:^4.0
```

Commit `composer.json` and `composer.lock` together. Keep feature flags disabled
until the application's compatibility tests pass.

## Scope resource callbacks

Filter-level `response(attributes: ...)` and `response(after: ...)` now run only on
the resource they configure. They no longer run on identifier-only nested records.
Resource attribute callbacks run before shared `ControllerContract::responseAttributes()`;
shared null omission or date presentation therefore cannot erase input needed to
resolve a resource-specific alias.

For application-wide conventions, retain the shared controller-contract hooks.
For one resource, declare its differences in its existing filter. Reuse that
filter with `response(relations: ['items' => ItemController::class])` rather than
repeating its mapping in the parent or branching on identifiers in a shared hook.
Related record declarations do not execute root selection, routes or actions.

A payload reader can now receive the normalized action as its second argument.
Readers that need only the controller remain valid. Move authorization side
effects out of readers into `request(before: ..., actions: [...])`: structural
guards run first, then native checks, then mapping and one upstream attempt.

See [contract adaptation](api-adaptation.md#reuse-related-resource-declarations)
for role-specific relation metadata, selection, guards and operation results.

## Earlier upgrade: 2.x to 3.0

Version 3.0 consolidates application integration into the existing `Strangler`
fluent object and an optional shared `ControllerContract`. It removes the previous
request callback methods and moves response helpers into dedicated namespaces.
There are no compatibility aliases for the removed API.

### Update the dependency

```shell
composer require cosmira/yii1-strangler-proxy:^3.0
```

Commit `composer.json` and `composer.lock` together. Keep migrated features disabled
until your application contract tests pass with the new configuration.

### Replace request callback methods

Move callbacks into `request()` named arguments:

| 2.x | 3.0 |
| --- | --- |
| `payloadUsing($reader)` | `request(reader: $reader)` |
| `payloadUsing(reader: $reader, bypass: $predicate)` | `request(reader: $reader, bypass: $predicate)` |
| `routeParametersUsing($resolver)` | `request(parameters: $resolver)` |

The callback arguments retain their meanings. The payload reader returns the
application's parsed array. The payload predicate receives the original payload,
action, and query. The parameter resolver returns route-only values, or `false`
to keep the request in Yii before HTTP. Explicit readers take precedence over a
controller contract's reader, regardless of fluent call order.

For a parent stored on an existing native model, use `within('PARENT_ID')` with a
controller contract instead of writing a scoped lookup callback. The package
looks up the parent only when the selected route contains `{parent_id}`. A missing
or out-of-scope record keeps the request in Yii without contacting the backend.

### Update imports

| 2.x namespace | 3.0 namespace |
| --- | --- |
| `Cosmira\Strangler\Json` | `Cosmira\Strangler\Response\Json` |
| `Cosmira\Strangler\Record` | `Cosmira\Strangler\Response\Record` |
| `Cosmira\Strangler\JsonResource` | `Cosmira\Strangler\Response\JsonResource` |
| `Cosmira\Strangler\Mapping` | `Cosmira\Strangler\Schema\Mapping` |
| `Cosmira\Strangler\InvalidUpstreamResponse` | `Cosmira\Strangler\Exception\InvalidUpstreamResponse` |

`Strangler` and `Query` remain in `Cosmira\Strangler`. The new `ControllerContract`
is in the same namespace. `Fields` and `Includes` remain in
`Cosmira\Strangler\Schema`; the new `Format` helper is also in that namespace.

### Replace repeated application adapters

Connect your existing controller's native model and payload methods through one
[`ControllerContract`](../README.md#connect-existing-rest-controllers). Continue
using `Strangler::proxy()` directly in filters. It supplies the common request,
response, and error pipeline, so a separate application builder is unnecessary.

Declare ordinary resources with `uppercase('ITEM_ID')` and routes. Describe field
aliases, relation identifiers, value conversions, and projections only where the
two contracts differ. The package does not decide which fields, filters, or sorts
the new backend supports.

Shared [response hooks](api-adaptation.md#share-a-controller-contract) apply to root
and related records. Filter-level `response(relations: [...])` declarations override
shared relations with the same source name. Explicit stage mappers still replace
their configured stage; they do not implicitly call the conventional mapper.

With a controller contract, conventional CRUD requests also check the incoming
method, filter container, object payload shape, and conflicting identifiers.
Keep authentication, CSRF, and resource-access filters before Strangler. These
checks do not authorize the backend request.

The contract's default error formatter uses Yii's `state` and `error` envelope.
Validation errors become HTTP 400, with field messages represented as metadata
keys. `validationErrors()` can preserve an application's native validator codes.
Override `errorCodes()` for a different vocabulary, retaining the defaults with
`array_replace(parent::errorCodes(), [...])`. Full custom error callbacks take
precedence over the contract's formatter.

## Check changed routes before enabling them

Run the application's existing tests for each changed filter. Include an item read,
a collection read, writes, validation failures, and denied access. For nested
routes, cover a missing item, an out-of-scope item, and a conflicting supplied
parent. Confirm the legacy response names and relation shapes expected by the
client.

For binary routes, exclude automatic JSON mapping with `uppercase(..., except:
['download'])`. The bounded-memory file forwarding introduced in 2.1 remains
available. Large unmapped responses can use temporary disk space in the HTTP
handler; a successful contract test is not a production load measurement.
