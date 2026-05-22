# Error Codes

Catalog of NeNe REST API error codes. The runtime source of truth is `config/error_codes.php`; this file restates the catalog in a form OpenAPI consumers can read alongside `docs/api/openapi.yaml`.

For the envelope shape rationale, see ADR 0003.

## Envelope

Every failure response uses the same envelope, documented in OpenAPI as `ApiFailureEnvelope`:

```json
{
  "Result": true,
  "Data": {
    "status": "failure",
    "errorCode": "TODO-NOT-FOUND",
    "errorMessage": "TODO item was not found."
  }
}
```

`Result` stays `true` because the response was produced by the controller (a 5xx wraps a different shape). `Data.status` is always `failure` for the codes below. The HTTP status is set by the controller based on `httpStatus` in the runtime catalog.

## Catalog

| Error code | HTTP status | Message | Notes |
| --- | --- | --- | --- |
| `SESSION-CLOSED` | 401 | Session timeout. Please log in again. | Returned when an authenticated endpoint is called without a valid `PHPSESSID` cookie. |
| `LOGIN-FAILED` | 401 | Wrong user ID or user PASS | Returned by `POST /session/login` for rejected credentials. |
| `CSRF-TOKEN-INVALID` | 403 | Invalid CSRF token. | Returned when a state-changing request from a logged-in client is missing or has a wrong `X-CSRF-Token` header. |
| `METHOD-NOT-ALLOWED` | 405 | The HTTP method is not allowed for this endpoint. | Returned with the `Allow` response header listing valid methods. |
| `NOT-FOUND` | 404 | The requested resource was not found. | Emitted by the dispatcher when the route resolves to no controller or no action **and** the request's `Accept` header prefers `application/json`. HTML callers still receive the static `404.html` page. |
| `INTERNAL-ERROR` | 500 | An unexpected internal error occurred. | Emitted by `htdocs/index.php` when an unhandled `\Throwable` reaches the top-level catch on a REST request. HTML callers receive the static `500.html` page instead. |
| `ROUTE-CONFLICT` | 500 | Route configuration conflict. | Internal — surfaces when controller dispatch is ambiguous. |
| `TODO-ID-REQUIRED` | 400 | TODO id is required. | `/todo/item/id_X` with missing or non-numeric `id`. |
| `TODO-NOT-FOUND` | 404 | TODO item was not found. | `/todo/item/id_X` where no row matches the signed-in user. |
| `TODO-TITLE-REQUIRED` | 400 | TODO title is required. | `POST /todo/index` or `PUT /todo/item/id_X` with empty `title`. |

## Adding a new error code

1. Add the entry to `config/error_codes.php` with `message` and `httpStatus`.
2. Add a row to the table above. Match the order of the runtime file so the two are easy to diff.
3. If the code is referenced from a new OpenAPI endpoint, the endpoint's failure response references the shared `ApiFailureEnvelope` — no per-code schema is added. The endpoint MAY include an `example` showing the specific `errorCode` value it produces.
4. The contract test (`tests/Http/OpenApiRuntimeContractTest`) automatically discovers new endpoints and asserts that observed statuses appear in the documented status list. No test changes are needed for a new error code on an existing endpoint.

## Response decoration and the error-path early-exit trap

NeNe currently emits no framework-level decoration on top of the envelope (no security headers, no request IDs). If a future change adds such decoration, the *placement* matters because several error paths exit before `ControllerBase::run()` returns:

- `ControllerBase::sessionCheck()` emits the `SESSION-CLOSED` 401 envelope (or the `unauthorizedRedirect()` 302) and terminates.
- `ControllerBase::run()`'s CSRF check emits the `CSRF-TOKEN-INVALID` 403 envelope and terminates.
- `Dispatcher::outputJsonFailure()` emits the `METHOD-NOT-ALLOWED` 405 envelope and terminates before `ControllerBase::run()` is ever called.
- `Dispatcher::notFoundResponse()` emits the 404 response before `ControllerBase::run()` is ever called.
- The top-level `\Throwable` catch in `htdocs/index.php` runs *after* `run()` returned (or threw), but skips `run()`'s tail entirely.

**Place cross-cutting response decoration in `Nene\Xion\HttpEmitter` (or wrap `HttpEmitter::emit()`) — not in `ControllerBase::run()`'s tail.** Decoration added at `run()`'s tail will not reach 401 / 403 / 404 / 405 / 500 responses, even though it reaches every 2xx.

This is the PHP analogue of the nene2-python FT75 LIFO-middleware trap. The trap is currently silent (there is nothing to skip), but it must be respected the moment any framework-wide response header is added. Surveyed and confirmed in FT7 (`docs/field-trials/2026-05-field-trial-7.md` F-6).

## Related

- `config/error_codes.php` — runtime catalog.
- `docs/api/openapi.yaml` — OpenAPI contract; references `ApiFailureEnvelope` for every failure response.
- `docs/api/reference-client.md` — failure-mode table for external consumers.
- ADR 0003 — rationale for the generic envelope shape.
