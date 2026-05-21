# ADR 0003: OpenAPI Failure Envelope Shape

## Status

Accepted

## Context

NeNe's REST contract returns failures in a standard envelope:

```json
{ "Result": true, "Data": { "status": "failure", "errorCode": "...", "errorMessage": "..." } }
```

`config/error_codes.php` is the runtime source of truth for error codes and their HTTP statuses. The OpenAPI contract under `docs/api/openapi.yaml` documents the shape consumers see.

The initial TODO sample adopted a **per-error-code envelope schema** pattern: every error code (`TODO-NOT-FOUND`, `TODO-ID-REQUIRED`, `TODO-TITLE-REQUIRED`, `SESSION-CLOSED`, `LOGIN-FAILED`, `CSRF-TOKEN-INVALID`, `METHOD-NOT-ALLOWED`, ...) gets its own envelope schema in `#/components/schemas/` that pins `errorCode` and `errorMessage` to constants:

```yaml
TodoNotFoundEnvelope:
  allOf:
    - $ref: "#/components/schemas/ErrorEnvelope"
    - properties:
        Data:
          properties:
            errorCode:
              const: TODO-NOT-FOUND
            errorMessage:
              const: TODO item was not found.
```

Each endpoint then references the specific envelope under each status. The shape is rich: consumers reading the OpenAPI see the exact error code and message constants for every documented failure.

Field Trial 2 (`docs/field-trials/2026-05-field-trial-2.md`, F-5) flagged the boilerplate cost when adding two new entities (Bookmark + Tag). The trial used a single generic `ApiFailureEnvelope` instead and recorded the choice as `defer` pending a third sighting. Field Trial 3 (`docs/field-trials/2026-05-field-trial-3.md`, F-1) added a third entity (`Memo`) and again paid the per-code boilerplate, meeting the escalation trigger documented in `docs/field-trials/follow-ups.md`. Issue #251 escalated the decision to an ADR.

## Decision

NeNe adopts a **single generic `ApiFailureEnvelope` schema** as the canonical failure response shape across the OpenAPI contract. Per-error-code envelope schemas are removed.

Specifically:

- `docs/api/openapi.yaml` defines one `ApiFailureEnvelope` schema. Every documented failure response (`400`, `401`, `403`, `404`, `405`) references it directly or via a reusable `#/components/responses/` entry.
- `errorCode` in `ApiFailureData` is typed `string` with no `const`. The OpenAPI document does not enumerate codes per endpoint.
- The canonical list of error codes, their messages, and their HTTP statuses lives at `docs/development/error-codes.md`. `config/error_codes.php` remains the runtime source of truth; the markdown file restates it for consumers reading the contract.
- When a new error code is added, the only documentation step is one row in `docs/development/error-codes.md`. No envelope schema additions, no per-endpoint enum updates.
- `application/json` content for failure responses may include `examples` showing the specific `errorCode` value the endpoint actually returns, so the per-endpoint documentation is not lost — it just moves out of `schemas`.

This decision applies to public OpenAPI shape only. The runtime catalog (`config/error_codes.php`) and the `ApiResponse::failure($code)` API are unchanged.

## Consequences

- The OpenAPI document shrinks materially. The 12 per-error-code envelope schemas (`TodoIdRequiredEnvelope`, `TodoNotFoundEnvelope`, `TodoTitleRequiredEnvelope`, `SessionClosedEnvelope`, `LoginFailedEnvelope`, `CsrfTokenInvalidEnvelope`, `MethodNotAllowedEnvelope`, plus FT3's three Memo envelopes) collapse to one shared `ApiFailureEnvelope`.
- Adding a new entity becomes cheaper. Each new endpoint only needs success / request schemas; failure responses reuse the existing shape.
- Per-code documentation moves from OpenAPI to `docs/development/error-codes.md`. Consumers who relied on Swagger UI to see the exact constants now click through to the markdown file. The trade-off is accepted because the runtime catalog (`config/error_codes.php`) was already the source of truth and the per-envelope constants in OpenAPI were duplicating it.
- Endpoint responses may still include `examples` showing the actual `errorCode` value, so the link between an HTTP status and its likely error code stays visible per operation without being structurally enforced.
- Existing consumers see no payload change: the JSON envelope shape (`Result` / `Data.status` / `Data.errorCode` / `Data.errorMessage`) is identical. Only the OpenAPI schema names change. Generated clients (which key off `operationId` and request/response schema names) will rename their failure types from `TodoNotFoundEnvelope` etc. to `ApiFailureEnvelope`.
- The `OpenApiRuntimeContractTest` shape (PR #255) continues to work: it iterates documented operations and asserts the observed status is in the documented status list, which is independent of the envelope schema name.
- A future trial may re-surface the documentation gap (a contributor adds a new error code but forgets to update `docs/development/error-codes.md`). Mitigation paths if that happens: (a) add a contributing check, (b) auto-generate the markdown file from `config/error_codes.php`, (c) re-evaluate the trade-off via another ADR.

## Related

- Issue #251 — escalation that produced this ADR.
- `docs/field-trials/2026-05-field-trial-2.md` F-5 — original sighting.
- `docs/field-trials/2026-05-field-trial-3.md` F-1 — third-sighting escalation.
- `docs/field-trials/follow-ups.md` — the trigger rule that fired.
- ADR 0002 — field-trial methodology that produced the escalation.
- `config/error_codes.php` — runtime source of truth for error codes.
- `docs/development/error-codes.md` — new canonical markdown reference.
