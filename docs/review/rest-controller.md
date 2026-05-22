# REST Controller Self-Review

Use this checklist for new or modified REST handlers (`indexGetRest`, `indexPostRest`, `itemPatchRest`, `itemDeleteRest`, ...), API error catalog additions, and changes to the REST auth / CSRF path.

Source policies:

- `docs/tutorials/building-a-service.md` (sections "Add a REST Endpoint" and onward)
- `docs/development/coding-standards.md`
- `docs/development/error-codes.md`
- `docs/api/README.md`
- `docs/api/reference-client.md`

## Checklist

- [ ] Method-specific handler names (`indexGetRest`, `indexPostRest`, etc.) are used — no new `indexRest` legacy-fallback handlers.
- [ ] State-changing methods (`POST` / `PUT` / `PATCH` / `DELETE`) rely on the framework's automatic CSRF gate when the user is logged in; no per-handler CSRF re-implementation.
- [ ] `SESSION_CHECK` is left at the default `true` for protected endpoints; only set to `false` in `preAction()` for public endpoints with a documented reason.
- [ ] Success responses use `$this->API_RESPONSE->success([...])` with a stable `Data` shape.
- [ ] Mapper rows are cast through a per-controller `normalizeRow()` (or equivalent) helper before returning. Never return raw `fetch(PDO::FETCH_ASSOC)` rows — every value is a string until the controller casts it (`(int)$row['id']`, `(bool)$row['is_X']`, ...). See `docs/tutorials/building-a-service.md` § "Normalize the row before returning".
- [ ] Failure responses use `$this->API_RESPONSE->failure('ERROR-CODE')` with a code that exists in `config/error_codes.php`.
- [ ] New error codes are added to `config/error_codes.php` **and** documented in `docs/development/error-codes.md` (catalog table).
- [ ] Per-error envelope schemas are **not** added to `docs/api/openapi.yaml`; the contract uses the canonical generic `ApiFailureEnvelope` (ADR-0003) with per-endpoint `examples` showing specific codes.
- [ ] Path parameters use the NeNe `id_X` URL form (e.g. `/todo/item/id_1`) and are extracted via `$this->request->getParam('id')`; reject non-numeric ids with `XXX-ID-REQUIRED`.
- [ ] Mappers are scoped per user where the data is per-user (e.g. `findRowsByUserId($this->AUTH_SESSION->userId())`); never expose another user's rows.
- [ ] `TransactionManager` wraps multi-statement or multi-mapper writes; domain validation runs **outside** the transaction (escape via `DomainException` if it must throw from within — see PR #244).
- [ ] OpenAPI documents the new operation: path, method, request body schema, success envelope schema, failure status codes referencing `ApiFailureEnvelope` with example `errorCode`.
- [ ] `composer test` and `composer test:http` both pass. The runtime contract test (`OpenApiRuntimeContractTest`) auto-discovers the new operation; no manual probe tuple to add.
- [ ] PR body lists this checklist and the per-entity HTTP smoke test added under `tests/Http/`.
