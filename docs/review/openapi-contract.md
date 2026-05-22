# OpenAPI Contract Self-Review

Use this checklist for any change to `docs/api/openapi.yaml`, the contract test (`tests/Http/OpenApiRuntimeContractTest.php`), or related schema / response components.

Source policies:

- `docs/api/README.md`
- `docs/development/error-codes.md`
- `docs/adr/0003-openapi-failure-envelope-shape.md`
- `docs/tutorials/building-a-service.md` (section "Update OpenAPI")

## Checklist

- [ ] Every new public REST operation appears in `docs/api/openapi.yaml` with `tags`, `summary`, `operationId`, request body schema (if any), and a response per documented HTTP status.
- [ ] Failure responses reference the canonical **generic** `ApiFailureEnvelope` schema (ADR-0003). No per-error-code envelope schemas are added; preserved-per-endpoint specificity goes into `examples:` showing the actual `errorCode` value.
- [ ] Path parameters use NeNe's `/path/item/id_{id}` form and document a `parameter` referenced from `#/components/parameters/...` (e.g. `TodoId`, `MemoId`).
- [ ] Security requirements (`sessionCookie` / `csrfToken`) match the actual gate behavior. `GET` operations only declare `sessionCookie`; state-changing operations declare both.
- [ ] `requestBody.content['application/json'].examples` includes at least one example. The runtime contract test (`OpenApiRuntimeContractTest`) auto-discovers operations and uses the first example as the probe body.
- [ ] Reusable responses (`#/components/responses/MethodNotAllowed`, `#/components/responses/SessionClosed`, `#/components/responses/CsrfTokenInvalid`, `#/components/responses/TodoNotFound`, ...) are used where multiple operations share the same response shape. State-changing operations that require auth should `$ref` `SessionClosed` (401) and `CsrfTokenInvalid` (403) rather than inlining the envelope.
- [ ] New error codes are added to `config/error_codes.php` **and** `docs/development/error-codes.md` table; OpenAPI references them via `examples` only.
- [ ] Operations with destructive side effects that should not be auto-probed by the runtime contract test set `x-nene-runtime-probe: skip` (currently unused — first use will validate the opt-out path).
- [ ] `symfony/yaml` parses the modified file (`docker compose exec -T app php -r 'Symfony\Component\Yaml\Yaml::parseFile("docs/api/openapi.yaml");'` returns without exception).
- [ ] `composer test:http` passes; the contract test exercises all documented operations.
- [ ] Swagger UI at `http://localhost:8080/api-docs/` renders without errors after the change.
- [ ] PR body lists this checklist when OpenAPI changes.
