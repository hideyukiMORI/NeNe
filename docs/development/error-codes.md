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
| `UPLOAD-FILE-REQUIRED` | 400 | Upload file is required. | Thrown by `UploadedFile::validate()` when the upload is missing or `is_uploaded_file()` returns false. |
| `UPLOAD-TOO-LARGE` | 413 | Upload exceeds size limit. | Thrown by `UploadedFile::validate(['maxBytes' => N])` when `size() > N`. |
| `UPLOAD-MIME-REJECTED` | 415 | Upload mime type is not allowed. | Thrown by `UploadedFile::validate(['allowedMime' => [...]])` when `finfo` mime is not on the allowlist. |
| `TOKEN-EXPIRED` | 410 | The reset token has expired. | Returned by password-reset complete endpoint when `PasswordResetToken::isExpired()` is true. |
| `TOKEN-ALREADY-USED` | 409 | The reset token has already been used. | Returned by password-reset complete endpoint when `used_at` is not null. |

## Adding a new error code

1. Add the entry to `config/error_codes.php` with `message` and `httpStatus`.
2. Add a row to the table above. Match the order of the runtime file so the two are easy to diff.
3. If the code is referenced from a new OpenAPI endpoint, the endpoint's failure response references the shared `ApiFailureEnvelope` — no per-code schema is added. The endpoint MAY include an `example` showing the specific `errorCode` value it produces.
4. The contract test (`tests/Http/OpenApiRuntimeContractTest`) automatically discovers new endpoints and asserts that observed statuses appear in the documented status list. No test changes are needed for a new error code on an existing endpoint.

## HTML rendering of domain failures

When a controller throws `Nene\Xion\DomainException` from inside an HTML `*Action()` method, the top-level catch in `htdocs/index.php` branches on `RouteContext::isRest()`:

- REST callers receive the JSON `ApiFailureEnvelope` carrying the thrown `errorCode`, with HTTP status from the catalog (no change from prior behavior).
- HTML callers receive the static `domain-error.html` page at the project root with `{{errorCode}}` and `{{errorMessage}}` placeholders substituted by `htmlspecialchars`-escaped values from the catalog. HTTP status still comes from the catalog (e.g. `TODO-NOT-FOUND` → 404).

The HTML rendering uses a static file and simple `strtr()` substitution rather than Smarty so the catch path does not need to bootstrap a view layer. Replace the template at the project root to rebrand or extend the markup.

## Response decoration and the error-path early-exit trap

NeNe currently emits no framework-level decoration on top of the envelope (no security headers, no request IDs). If a future change adds such decoration, the *placement* matters because several error paths exit before `ControllerBase::run()` returns:

- `ControllerBase::sessionCheck()` emits the `SESSION-CLOSED` 401 envelope (or the `unauthorizedRedirect()` 302) and terminates.
- `ControllerBase::run()`'s CSRF check emits the `CSRF-TOKEN-INVALID` 403 envelope and terminates.
- `Dispatcher::outputJsonFailure()` emits the `METHOD-NOT-ALLOWED` 405 envelope and terminates before `ControllerBase::run()` is ever called.
- `Dispatcher::notFoundResponse()` emits the 404 response before `ControllerBase::run()` is ever called.
- The top-level `\Throwable` catch in `htdocs/index.php` runs *after* `run()` returned (or threw), but skips `run()`'s tail entirely.

**Place cross-cutting response decoration in `Nene\Xion\HttpEmitter` (or wrap `HttpEmitter::emit()`) — not in `ControllerBase::run()`'s tail.** Decoration added at `run()`'s tail will not reach 401 / 403 / 404 / 405 / 500 responses, even though it reaches every 2xx.

This is the PHP analogue of the nene2-python FT75 LIFO-middleware trap. Surveyed in FT7 (`docs/field-trials/2026-05-field-trial-7.md` F-6); FT8 F-4 (#331) addressed the access-log corner of it; **FT14 / ADR-0007** (`docs/adr/0007-response-decoration-boundary.md`) introduced `Nene\Xion\ResponseDecorator` as the canonical boundary class. New cross-cutting concerns now plug into `ResponseDecorator::headers()` (via env) or `ResponseDecorator::decorate()` / `sendHeaders()` (via code) — see `docs/development/security-headers.md`. The "trap" wording is preserved here for historical context; the resolution is `ResponseDecorator`.

## Related

- `config/error_codes.php` — runtime catalog.
- `docs/development/error-rendering.md` — how each catalog code renders on the REST vs HTML side (404 / 500 / domain failure / CSRF / auth / 405).
- `docs/api/openapi.yaml` — OpenAPI contract; references `ApiFailureEnvelope` for every failure response.
- `docs/api/reference-client.md` — failure-mode table for external consumers.
- ADR 0003 — rationale for the generic envelope shape.
