# Check a 9.x integration

Keep the feature disabled until the application's contract tests pass with
`cosmira/yii1-strangler-proxy` **9.3.0**.

## Upgrade from 9.2

Require `cosmira/yii1-strangler-proxy:^9.3` to use `action(..., checkNative: true)`.
Declare the native relations the action handles through `request(relations: ..., actions: ...)`.
The same application bypass and unhandled-relation checks apply before any write.
See [a composed action with the controller contract](../README.md#apply-the-controller-contract-to-a-composed-action).

A `request(bypass: ..., actions: [...])` callback now runs only for the listed
actions. Omit `actions` for a callback shared by all actions.

## Upgrade from 9.1

Require `cosmira/yii1-strangler-proxy:^9.2` to use `write()` in a custom action.
Use it when the next step needs a specific success status or a receipt for the resource that changed.
See [confirming a write](api-adaptation.md#send-a-request-from-a-yii-filter).

## Upgrade from 9.0

Existing routes keep their behavior. To use `choose()`, require
`cosmira/yii1-strangler-proxy:^9.1`. Test each supported form and the form
that should stay in Yii. See [choosing a route](../README.md#choose-a-route-from-the-submitted-form).

## Upgrade from 8.4

1. In your Yii application, run `composer require cosmira/yii1-strangler-proxy:^9.2`
   and review the updated lock file.
2. Check which HTTP method each existing client actually sends. Routes using
   `jsonFields()` accept the outgoing verb by default. Declare a different input verb
   with `->put('savePreferences', $path, incoming: 'POST')`. Other existing HTTP routes
   retain their 8.x method checks when `incoming` is omitted. An `action()` declaration
   takes the incoming method as its second argument. An explicit `incoming` overrides
   the conventional verb for that route. Its input, identity and native relation checks still apply.
3. If your application overrides `ControllerContract::shouldBypass()`, add
   `?string $incoming = null` as the last parameter. Pass it to `parent::shouldBypass()`
   when delegating to the package.
4. For an action that reads and writes the same plain object, replace its custom field
   callbacks with [`jsonFields()`](api-adaptation.md#json-object-fields).
   Check a complete form, a missing field, a present null and a malformed backend response.
5. Where one action sends several writes, exercise a failure after the first successful write.
   Check the HTTP response and the persisted state of each affected resource.
   See [multiple REST requests](api-adaptation.md#send-a-request-from-a-yii-filter).

## Check the configuration

- Complete authentication, permissions, incoming-method, and CSRF checks before Strangler.
- Keep shared conventions in `ControllerContract` and resource differences in existing controller filters.
- Let each native model supply its primary key. Building filters must not read model metadata.
- Keep payload readers free of writes and authorization side effects. Use `request(before:)` for native checks.
- Make related controllers safe to construct and read through `filters()` without running an action.
- Exclude binary responses from JSON mapping and check upload limits and buffering.

See [integration](integration.md) for filter order and lifecycle requirements,
and [API adaptation](api-adaptation.md) for guards, callbacks, and related resources.

## Check behavior with the flag off and on

Use isolated, equivalent data. Cover the cases affected by each changed route:

- Item and collection reads, pagination, filters, and requested relations.
- Writes, validation errors, denied access, and conflicting identifiers.
- Missing or partial records and nested routes with an out-of-scope parent.
- An absent `with` versus `with=[]`, when selection rules are configured.
- A lost write response, without replay in Yii.

Before enabling traffic, follow the [operations guide](operations.md).
Package tests do not establish compatibility with your application.
