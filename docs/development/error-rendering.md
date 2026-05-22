# Error Rendering

How NeNe shapes error responses across the REST and HTML paths. Pair with `docs/development/error-codes.md` (the envelope catalog) — this document explains what the user *sees* on each side.

Audience: anyone writing a controller, contributor wiring a new error class, or a reviewer auditing a security-sensitive page. Trial source: FT7 (`docs/field-trials/2026-05-field-trial-7.md`).

## REST vs HTML response shape, at a glance

| Situation | REST caller (`Accept: application/json`) | HTML caller (browser, `Accept: text/html`) |
| --- | --- | --- |
| Route matches no controller / no action | 404 + `ApiFailureEnvelope` (`errorCode: NOT-FOUND`) — `application/json` | 404 + static `404.html` — `text/html` |
| Unhandled `\Throwable` in a `*Rest` handler | 500 + `ApiFailureEnvelope` (`errorCode: INTERNAL-ERROR`) | (would be the same path; see "Pre-dispatch errors" below) |
| Unhandled `\Throwable` in a `*Action` handler | (HTML caller path) | 500 + static `500.html` |
| `Nene\Xion\DomainException` thrown from any handler | catalog HTTP status + `ApiFailureEnvelope` carrying the `errorCode` | catalog HTTP status + `domain-error.html` template with `{{errorCode}}` / `{{errorMessage}}` placeholders substituted |
| Unauthenticated request to a protected `*Rest` action | 401 + `SESSION-CLOSED` envelope | (not directly applicable — see HTML below) |
| Unauthenticated request to a protected `*Action` action | (REST path) | 302 `Location:` redirect to `unauthorizedRedirect()` (default: `LOGOUT_URI`) |
| CSRF rejection on a state-changing `*Rest` request | 403 + `CSRF-TOKEN-INVALID` envelope (automatic in `ControllerBase::run()`) | (REST path) |
| CSRF rejection on an HTML POST form | (REST path) | 403 + static `csrf.html` — provided the controller called `requireCsrfFromPost()` (must-call helper added by #316) |
| Wrong HTTP method on an action (only the wrong-verb `*Rest` exists) | 405 + `METHOD-NOT-ALLOWED` envelope + `Allow:` header | (REST path) |
| Wrong HTTP method on an HTML action | (REST path) | NeNe has no HTML-rendered 405. Build it inside `*Action()` by branching on `$this->method` and rendering your own template; the framework does not auto-render. |

The full envelope catalog lives in `docs/development/error-codes.md` and the runtime source in `config/error_codes.php`.

## 404 — route resolution failures

`Dispatcher::notFoundResponse()` (`class/xion/Dispatcher.php`) handles both *no-controller* and *no-action* cases. It branches on the request's `Accept` header (see `Dispatcher::wantsJson()`):

- A JSON-preferring request (`application/json` or `application/*` with `q` outranking `text/html`) gets the `NOT-FOUND` JSON envelope.
- Everything else (no `Accept`, `text/html`, generic wildcard) gets the static `404.html` page at the project root.

`404.html` is not a Smarty template — replace the file to rebrand. The dispatcher itself does not distinguish between "controller class doesn't exist" and "controller exists but no method matches"; both surface as a single 404 shape on each side. If you need finer granularity inside a controller, throw `notFound()` (which reuses the same `404.html` plus 404 status).

## 500 — unhandled `\Throwable`

The top-level catch in `htdocs/index.php` branches on `RouteContext::isRest()`:

- REST: `ApiFailureEnvelope` (`errorCode: INTERNAL-ERROR`, HTTP 500).
- HTML: static `500.html` at the project root.

The thrown exception is *always* logged through `Xion\Log::getInstance('error')` regardless of the branch — the response body never contains the message or trace. This is intentional. If you need to surface the raw exception to a developer locally, read the log; do not re-introduce an `APP_DEBUG` branch on the body without an explicit ADR.

### Pre-dispatch errors

If an error fires before `ControllerBase::run()` calls `RouteContext::set(...)` (autoload failure, initializer crash, etc.), `RouteContext::getInstance()` is still at its default (`mode = 'Action'`). The catch will render the HTML 500 page even for a REST URL. Pure-dispatch errors that pre-empt `run()` (404 / 405 / `ROUTE-CONFLICT`) go through their own dispatcher emitters and are unaffected.

## Domain failures inside a controller

`Nene\Xion\DomainException` is the canonical way for a controller to surface a business failure with a catalog code (`TODO-NOT-FOUND`, `TODO-TITLE-REQUIRED`, ...). Throw it from anywhere inside the action and the top-level catch in `htdocs/index.php` handles the rendering:

- REST: JSON envelope with the carried `errorCode`, status from the catalog.
- HTML: `domain-error.html` template with `{{errorCode}}` and `{{errorMessage}}` placeholders, status from the catalog. The substitution uses `strtr()` after `htmlspecialchars()` escaping; the file is a plain HTML document with no Smarty bootstrap.

Adding a new domain code: add the entry to `config/error_codes.php`, document it in `docs/development/error-codes.md`, and throw it like any existing code. No template change is needed unless you want a different visual for one particular code.

## Auth: redirect vs envelope

`ControllerBase::sessionCheck()` branches on `RouteContext::isRest()`:

- REST (`*Rest` action): `HttpTermination` with the `SESSION-CLOSED` envelope, 401.
- HTML (`*Action`): redirect (302 + `Location:`) to `$this->unauthorizedRedirect()`. The default returns the framework-wide `LOGOUT_URI` constant; override `unauthorizedRedirect()` in a controller to send a specific protected section to a dedicated login page (ADR-0004 documents the hook).

Because the dispatcher prefers `*Rest` over `*Action` for the same verb (see [Action method precedence](coding-standards.md#action-method-precedence)), defining both shapes for one URL silently turns the HTML redirect into a JSON 401. Don't.

## CSRF protection

Two layers exist:

- **REST**: `ControllerBase::run()` calls `CsrfProtectionPolicy` automatically on logged-in state-changing requests (`POST` / `PUT` / `PATCH` / `DELETE`). A missing or wrong `X-CSRF-Token` header emits the 403 `CSRF-TOKEN-INVALID` envelope. No controller code is needed.
- **HTML forms**: opt-in per controller. Embed the token in the form via `$this->csrfToken()` and `<input type="hidden" name="csrf_token" value="{$t_csrf_token}">`. In the POST handler, call `$this->requireCsrfFromPost()` (added by #316) before any write. On failure it terminates with the 403 `csrf.html` page (HTML) or the same 403 envelope (REST), so a missing `if` cannot silently accept the request.

The lower-level `verifyCsrfFromPost(): bool` is still available for handlers that need to recover from a CSRF failure (re-render the form with field-level errors instead of a flat 403 page). Prefer `requireCsrfFromPost()` for everything else.

See the tutorial at `docs/tutorials/building-a-service.md` § "Protect an Authenticated Form" for the full skeleton.

## Method-mismatch (405)

`Dispatcher::resolveActionRoute()` returns status 405 when the action exists in REST mode for *other* verbs but not the requested one. The dispatcher emits the `METHOD-NOT-ALLOWED` envelope with the `Allow:` header listing supported verbs. This path is JSON-only — the framework does not currently render a 405 HTML page. If you have an HTML form that must reject a wrong verb, branch on `$this->method` inside `actionAction()` and render the response yourself.

## Authoring an HTML controller error response

When the response is purely controller-driven (validation, business rules), the conventional shapes inside an HTML `*Action()` are:

| What happened | Idiom |
| --- | --- |
| Path doesn't exist for an HTML view | `$this->notFound();` — reuses `404.html`, sets 404 |
| Auth failure (handled by the framework) | none — `sessionCheck()` runs in `run()` and redirects automatically |
| CSRF failure on a state-changing POST | `$this->requireCsrfFromPost();` — terminates with 403 `csrf.html` on failure |
| Business-rule failure with an envelope code | `throw new \Nene\Xion\DomainException('CODE');` — rendered via `domain-error.html` |
| Wrong HTTP method on a side-effect URL | guard with `if ($this->method !== 'POST') { $this->location('...'); return; }` |
| Validation failure that should re-render the form | render with `$this->VIEW->setTemplate('xxx/yyy.tpl')`; do not throw — re-display the form with field-level errors |

## Decoration ordering reminder

Several error paths exit before `ControllerBase::run()` returns. Cross-cutting response decoration (security headers, request IDs) belongs in `HttpEmitter` (or a wrapper around it) rather than at the tail of `run()`. See the "Response decoration and the error-path early-exit trap" section in `docs/development/error-codes.md` for the full list.

## Related

- `docs/development/error-codes.md` — envelope catalog + decoration trap.
- `docs/development/coding-standards.md` — Action method precedence, URL convention.
- `docs/review/html-controller.md` — HTML controller review checklist.
- `docs/review/rest-controller.md` — REST controller review checklist.
- `docs/tutorials/building-a-service.md` — Protect an Authenticated Form (CSRF), HTML/REST symmetry.
- `docs/field-trials/2026-05-field-trial-7.md` — the trial that surfaced every behavior documented above.
- ADR 0003 — generic `ApiFailureEnvelope` shape rationale.
- ADR 0004 — `unauthorizedRedirect()` hook for HTML auth.
