# Field Trial 7 — error-pages (404 / 500 / 401 / 403 / 405 across JSON and HTML)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #308.

## Date

2026-05-22

## Baseline

- NeNe ref: `ed53a74` (post-FT6 main with `docs(field-trials)` reflection log from PR #305)
- Clone path: `/home/xi/github/NeNe-FT/ft7-error-pages/` (created via `tools/nene-ft-new.sh error-pages`)
- Host ports: app=8087, mysql=3314 (auto-offset N=7)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default) and SQLite (parity)

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 45 / 45, 129 assertions |
| `composer test:http` (in-container `NENE_HTTP_BASE_URL=http://localhost`) | 21 / 21, 205 assertions, 1 expected skip (`HttpErrorExposureTest`) |

## Goal

Exercise NeNe's **error-rendering surface** end-to-end. Deliberately produce each error class (404, 500, 401, 403, 405, CSRF-INVALID) on both the JSON API path (`ApiFailureEnvelope` per ADR-0003) and the HTML/Smarty path. Record consistency and rendering as `F-N`. The positive HTML/REST paths were already trialled in FT3–FT5; the error path had never been trialled.

## Service Built

- Name: `ft7probe` + `ft7authprobe` — two **non-committed** probe controllers, deliberately throwing each error class on both `*Action` (HTML) and `*Rest` (JSON) methods, on both public and auth-required URLs.
- Domains: none — no entity, no DB writes.
- Surface: ~10 ad-hoc URLs hit with `curl`.
- DB tables: none added (admin seed reused for login).

The probes live only in the clone. They are not part of the framework deliverable.

## Steps Taken

### 1. Cold source survey (no Docker required)

Read the dispatch entry, `Dispatcher::notFoundResponse()`, `ControllerBase::run()`/`sessionCheck()`/`verifyCsrfFromPost()`, `htdocs/index.php` exception catches, and `class/xion/ApiResponse` / `JsonResponder`. The survey produced eight pre-live hypotheses (H-A … H-H). Five were confirmed in source before the first request was sent.

### 2. JSON error tour

- `GET /no-such-controller/foo` (no Accept header) → **404, `Content-Type: text/html`**, body = `404.html` from the framework root.
- Same request with `Accept: application/json` → identical (HTML, Accept ignored).
- `GET /health/nosuchaction` → identical 404.html. Controller-not-found and action-not-found are indistinguishable.

**Finding (F-1)**: `Dispatcher::notFoundResponse()` (`class/xion/Dispatcher.php:230-234`) emits the static `404.html` for every 404, regardless of the client's `Accept` header. REST clients receive HTML rather than the ADR-0003 envelope.

### 3. Uncaught throwable

- `GET /ft7probe/throw` (deliberately throws `\RuntimeException` inside a REST handler) → **500, `Content-Type: text/plain`**, body = the raw exception message.
- With `APP_DEBUG=0` (set explicitly via env), body is `"Internal Server Error"`.

**Finding (F-2)**: The top-level `\Throwable` catch in `htdocs/index.php:47-52` returns `text/plain` for both REST and HTML callers. REST loses the ADR-0003 envelope, HTML has no template. `APP_DEBUG=1` (default outside production) leaks the raw exception message in the body.

### 4. `DomainException` from an HTML controller

- `GET /ft7probe/domain` where `domainAction()` throws `Nene\Xion\DomainException('TODO-NOT-FOUND')` → **404, `Content-Type: application/json`**, body = the ADR-0003 failure envelope.

**Finding (F-3)**: The `DomainException` catch in `htdocs/index.php:42-46` always renders JSON. There is no branch on `RouteContext::isAction()`, so an HTML controller that throws a `DomainException` sends a JSON envelope to the browser instead of a rendered error template.

### 5. `*Rest` vs `*Action` precedence

A probe controller defined both `postonlyPostRest()` (JSON) and `postonlyAction()` (HTML) for the same `postonly` action name.

- `POST /ft7probe/postonly` → **200, JSON** (took `*Rest` path).
- `GET /ft7probe/postonly` (no `*GetRest` defined) → **200, HTML** (fell to `*Action` path).

**Finding (F-4)**: `Dispatcher::resolveActionRoute()` (`class/xion/Dispatcher.php:109-135`) preferentially matches `*Rest` over `*Action`. There is no `Accept`-header content negotiation. A controller cannot offer an HTML view and a JSON variant of the *same* HTTP verb; an HTML browser hitting such a URL will receive JSON. This is the mechanism behind several of the surprises in steps 4 and 6.

### 6. Auth error tour

- Logged-out `GET /ft7authprobe/json` (REST-only) → **401, `SESSION-CLOSED` JSON envelope**.
- Logged-out `GET /ft7authprobe/index` (HTML-only `indexAction`) → **302 Found, `Location: /` (LOGOUT_URI)**.
- An earlier attempt that also defined a `*GetRest` for the same URL produced 401 JSON instead of a 302 — the F-4 precedence rule sent an HTML caller down the REST path.

The redirect-vs-envelope split works *if* a controller is HTML-only or REST-only for a given verb. Mixed shapes silently lose the redirect because of F-4.

### 7. CSRF rejection

- Logged-in `POST /ft7authprobe/submit` (HTML, `csrf_token=bogus`) → **200 OK**, rendered HTML body. The probe controller called `$this->verifyCsrfFromPost()` but did not branch on its return value, so the form silently "succeeded".
- Logged-in `POST /ft7authprobe/submitJson` with bad CSRF → **403, `CSRF-TOKEN-INVALID` JSON envelope** (REST path, automatic check in `ControllerBase::run()`).

**Finding (F-5)**: `verifyCsrfFromPost()` (`class/xion/ControllerBase.php:431-435`) returns `bool`. A controller that forgets to branch on the return value silently accepts an invalid CSRF token. The REST equivalent in `ControllerBase::run():206-208` is automatic. The HTML path is one missing `if` away from a silent CSRF bypass.

### 8. Method-mismatch + decoration check

- `GET /ft7authprobe/submitJson` (POST-only `submitJsonPostRest`) → **405, `Allow: POST`, JSON envelope `METHOD-NOT-ALLOWED`**. Matches `Dispatcher.php:55-56`.
- Header decoration: `Set-Cookie: PHPSESSID` is emitted on 200 / 401 / 404 / 405 / 500 when the client has no cookie; suppressed (PHP-default) when a cookie is supplied. No anomaly here.
- **No framework-level security headers** (X-Content-Type-Options, X-Frame-Options, CSP, Referrer-Policy, HSTS) on any response — error or success.
- The `sessionCheck` / CSRF-reject / `outputJsonFailure` paths exit *before* `ControllerBase::run()`'s tail returns. Any future response-decoration added at the `run()` boundary will silently skip 401 / 403 / 405 responses.

**Finding (F-6)**: The error-path early-exit is currently silent (there is nothing to skip), but it is the PHP analogue of the nene2-python FT75 LIFO trap. If anyone adds security headers or request-ID decoration at `run()`'s tail, those decorations will not reach error responses.

### 9. Documentation cross-check

- `docs/development/error-codes.md` documents the ADR-0003 envelope and the error catalog, but only from a REST perspective.
- No documentation explains how an HTML controller should emit 404 / 500 / 405 / 401 / 403, or how the `unauthorizedRedirect()` hook differs from the REST `SESSION-CLOSED` envelope, or that controller-not-found and action-not-found are indistinguishable.

**Finding (F-7 / F-8 / F-9)**: Three docs-gap findings — HTML error rendering is undocumented, the auth-redirect vs SESSION-CLOSED split is documented only by source, and the 404 / 500 single-template behavior is not called out.

## Results

| Scenario                                          | Expectation                            | Actual                                            | Status   |
| ------------------------------------------------- | -------------------------------------- | ------------------------------------------------- | -------- |
| REST 404 (no controller)                          | JSON envelope                          | HTML `404.html`                                   | Partial  |
| REST 404 (no action)                              | JSON envelope                          | HTML `404.html`                                   | Partial  |
| HTML 404 (no controller)                          | HTML `404.html`                        | HTML `404.html`                                   | Pass     |
| REST 500 (uncaught `\Throwable`)                  | JSON envelope                          | `text/plain` + leaked message (APP_DEBUG=1)       | Blocked  |
| HTML 500 (uncaught `\Throwable`)                  | rendered HTML template                 | `text/plain`                                      | Blocked  |
| REST `DomainException` from `*Rest`               | JSON envelope, status from catalog     | JSON envelope, 404                                | Pass     |
| HTML `DomainException` from `*Action`             | rendered HTML template                 | JSON envelope to the browser                      | Blocked  |
| REST 401 (logged out)                             | JSON `SESSION-CLOSED`                  | JSON `SESSION-CLOSED`                             | Pass     |
| HTML 401 (logged out, HTML-only action)           | 302 to LOGOUT_URI                      | 302 to LOGOUT_URI                                 | Pass     |
| HTML 401 (logged out, mixed-shape action)         | 302 to LOGOUT_URI                      | JSON 401 (F-4 precedence wins)                    | Blocked  |
| REST CSRF rejection (POST with bad token)         | JSON 403 `CSRF-TOKEN-INVALID`          | JSON 403 `CSRF-TOKEN-INVALID`                     | Pass     |
| HTML CSRF rejection (POST with bad token)         | rejected response                      | 200 OK (silent acceptance unless caller branches) | Blocked  |
| REST 405 (GET on POST-only)                       | JSON envelope, `Allow:` header         | JSON envelope, `Allow: POST`                      | Pass     |
| HTML 405 (GET on POST-only)                       | rendered HTML or 302                   | JSON envelope (no HTML branch exists)             | Partial  |
| Set-Cookie present on error responses             | yes                                    | yes (PHP default behavior, consistent)            | Pass     |
| Security headers present on error responses       | best-effort (CSP / XFO etc.)           | absent on every response, error or success        | Partial  |

## Friction Summary

| ID  | Location                                  | Severity | Kind             | Decision        |
| --- | ----------------------------------------- | -------- | ---------------- | --------------- |
| F-1 | `class/xion/Dispatcher.php:230-234`       | medium   | design-trade-off | fix-in-framework |
| F-2 | `htdocs/index.php:47-52`                  | medium   | feature-gap      | fix-in-framework |
| F-3 | `htdocs/index.php:42-46`                  | medium   | design-trade-off | fix-in-framework |
| F-4 | `class/xion/Dispatcher.php:109-135`       | medium   | design-trade-off | document        |
| F-5 | `class/xion/ControllerBase.php:431-435`   | high     | feature-gap      | fix-in-framework |
| F-6 | global (`htdocs/index.php` + `run()` tail) | low      | feature-gap      | document        |
| F-7 | `docs/development/error-codes.md`         | low      | docs-gap         | document        |
| F-8 | (no doc)                                  | low      | docs-gap         | document        |
| F-9 | `docs/development/url-conventions*`       | low      | docs-gap         | document        |

## Recommendations

### Immediate (documentation only)

1. **F-4 — Document `*Rest` over `*Action` precedence.** Add a "URL & action precedence" subsection to `docs/development/url-conventions.md` (or wherever FT4's URL conventions live) explaining that for the same `action` name and verb, `*Rest` wins; HTML callers cannot share a verb with a JSON variant.
2. **F-6 — Document the error-path early-exit trap.** Add a short note to `docs/development/error-codes.md` (or a new `docs/development/error-rendering.md`) warning that response-decoration added in `ControllerBase::run()`'s tail will not reach 401 / 403 / 405 / 500 responses. Recommend emitting cross-cutting headers from `HttpEmitter` instead.
3. **F-7 / F-8 / F-9 — Add `docs/development/error-rendering.md`.** One page covering (a) the JSON catalog, (b) the HTML side (redirect for auth, `notFound()` helper, manual 500 handling), and (c) the indistinguishable 404 / 405 / 500 cases. Replaces three small doc adds with one coherent doc.

### Suggested (small framework or template change)

1. **F-1 — Negotiate 404 by Accept header.** In `Dispatcher::notFoundResponse()`, return the ADR-0003 envelope when the request's `Accept` header indicates JSON (and fall through to `404.html` for HTML). One method, no API break.
2. **F-2 — Map unhandled `\Throwable` to envelope (JSON) or template (HTML).** In `htdocs/index.php:47-52`, branch on `RouteContext` mode: JSON callers get `INTERNAL-ERROR` envelope, HTML callers get a `template/error/500.tpl`. The leaked-message question is independent — APP_DEBUG-gating remains.
3. **F-3 — Branch the `DomainException` catch on mode.** Same shape as F-2: HTML callers get a rendered template using the envelope's `errorCode`, REST callers keep the current JSON behavior.
4. **F-5 — Add a must-call CSRF helper for HTML forms.** Provide `requireCsrfFromPost()` (or rename) that emits a 403 response (and `template/error/csrf.tpl`) on failure, so a missing branch can no longer accept the bad request. Keep `verifyCsrfFromPost()` as the underlying bool-returning helper for advanced cases.

### Trade-offs (needs ADR or discussion)

1. **F-4 — Per-verb HTML/JSON twin support.** If we ever want one URL to serve both `text/html` and `application/json` for the same verb based on `Accept`, that is a dispatcher change and an ADR. The current design (one verb, one shape per action) is consistent and predictable; flipping it makes route resolution depend on a header. The trial does **not** recommend changing this — only documenting it (F-4 above) and surfacing the trade-off for a future discussion.

## Aftermath

- Probe controllers stay inside the clone (`Ft7probeController.php`, `Ft7authprobeController.php`); not committed back to the framework.
- One follow-up Issue per actionable F-N filed in `hideyukiMORI/NeNe`, linked from #308.
- All Issues to be closed by merged PR before FT8 starts (per ADR-0002 cadence).
