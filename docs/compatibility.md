# Check a 5.2.0 integration

Keep the feature disabled until the application's contract tests pass with
`cosmira/yii1-strangler-proxy` **5.2.0**.

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
