# Field Trial 16 — agent-bearer-auth (cross-repo handoff from nene-mcp #380)

Methodology reference: `docs/field-trials/README.md`. Trial Issue: **#380** (cross-repo handoff from nene-mcp). New ADR: **ADR-0008** (optional Bearer for agent / MCP routes).

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT15 main (RequestId observability shipped, ADR-0007 generality validated).
- Clone path: `/home/xi/github/NeNe-FT/ft16-agent-bearer-auth/`
- Host ports: app=8096, mysql=3323
- PHP: 8.4.21
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `composer install` | 63 packages |
| `/health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 89 / 89 |
| `composer test:http` | 23 / 23, 1 expected skip |

## Goal

**Cross-repo handoff.** nene-mcp Issue #380 (originated from their FT204 / FT215 / FT225–FT419) documented that NeNe's TODO REST surface uses `sessionCookie` + `csrfToken`, which a stateless Bearer-proxy MCP server cannot satisfy (no cookie jar, no `X-CSRF-Token` plumbing).

Five acceptance criteria from the Issue body:

1. `GET /todo/index` → **200** with Bearer, no prior session.
2. `POST /todo/index` → **2xx** with Bearer, no cookie, no `X-CSRF-Token`.
3. `GET /todo/item/id_{id}` → **200** with Bearer for valid id.
4. OpenAPI reflects Bearer option on TODO routes.
5. Browser session+cookie flow **unchanged** (regression).

The trial uses Issue #380 directly as the trial Issue (no new issue) so the cross-repo trace stays clean — nene-mcp's confirmation FT will see the merged PRs and re-test their catalog.

## Service Built

This is a feature/refactor trial in NeNe's framework layer plus a small REST-surface fill.

- `Nene\Xion\BearerAuth` — pure-static helper. Reads `Authorization: Bearer <token>` (mod_php fallbacks), `hash_equals` against `NENE_AGENT_BEARER_TOKEN`, on success looks up the configured user (default `admin`) and binds it via `AuthSession::bindBearer()`.
- `AuthSession::bindBearer()` / `isBearerAuthenticated()` — in-memory per-request user binding. The PHP session cookie is not touched.
- `CsrfProtectionPolicy::requiresToken()` — new `bool $isBearerAuthenticated = false` parameter; when true, CSRF is skipped.
- `ControllerBase::requiresCsrfProtection()` — passes the bearer flag to the policy.
- `htdocs/index.php` — calls `BearerAuth::resolve()` after `session_start()`.
- `TodoController::itemGetRest()` — added (missing in baseline; nene-mcp catalog expected it).
- `UserMapper::findRowByUserIdentifier()` — new public method that strips the password hash.
- `docs/api/openapi.yaml` — new `bearerAuth` security scheme; new `GET /todo/item/id_{id}` operation; `listTodos` / `createTodo` / `updateTodo` / `deleteTodo` carry `bearerAuth` alongside the existing security.
- `tests/Unit/Xion/BearerAuthTest.php` (9 cases) + `CsrfProtectionPolicyTest` (+ 1 case) + `HttpSmokeTest` refactor (+1 new case).

## Steps Taken

### 1. Cold survey

`AUTH_SESSION` reads / writes `$_SESSION['xion']`. `CsrfProtectionPolicy::requiresToken(RouteContext, method, isLoggedIn)` — the gate that auto-rejects state-changing REST requests when the session is logged in. `ControllerBase::sessionCheck()` returns 401 SESSION-CLOSED for unauthenticated REST and redirects for HTML.

Bearer auth needs to:
- Authenticate before `sessionCheck()` runs (so `isLoggedIn()` returns true).
- Skip CSRF (the policy needs a new parameter).
- Not touch the session cookie (Bearer is stateless; no cookie should be set/regenerated).

### 2. Helper design

`BearerAuth::resolve()` is the single entry. It runs once per request. The implementation reads env, parses `Authorization`, `hash_equals` to compare, and on success calls `AuthSession::bindBearer($userRow)`. Failure (token missing / invalid / configured user missing) is a silent no-op — the request falls through to the normal `sessionCheck()` path and gets 401.

`AuthSession::bindBearer($user)` stores the user in an instance field (`$this->bearerUser`) rather than `$_SESSION`. `isLoggedIn()` / `user()` / `userId()` return the bearer-bound user when set. `session_regenerate_id()` is **not** called.

`CsrfProtectionPolicy::requiresToken()` gains a fourth parameter `bool $isBearerAuthenticated = false`. Default false preserves all existing call sites. When true, the policy returns false immediately.

### 3. mod_php Authorization quirk (F-1)

First implementation read `$_SERVER['HTTP_AUTHORIZATION']`. Returned 401 on T-1. Cold debug: `$_SERVER['HTTP_AUTHORIZATION']` is unset under `php:8.4-apache` mod_php — Apache strips it by default. `apache_request_headers()` still has it.

Fix: four-layer fallback (`$_SERVER['HTTP_AUTHORIZATION']` → `REDIRECT_HTTP_AUTHORIZATION` → `apache_request_headers()` → `getallheaders()`). All four are case-insensitive on the header name.

### 4. Missing `itemGetRest` (F-2)

After the Bearer wire fired, T-3 still failed: `GET /todo/item/id_5` → 405. The dispatcher's `Allow:` header listed `PUT, DELETE` but not `GET`. Baseline `TodoController` had **no** `itemGetRest` — the REST surface was list / create / update / delete, never "read one back by id." nene-mcp's FT204 catalog assumed read-by-id existed.

Added `itemGetRest()` (10 lines, mirrors `findRowByUserIdAndId` + normalize). Updated openapi.yaml with the new operation. Refactored one HTTP smoke test (`testUnsupportedRestMethodReturnsMethodNotAllowed` was asserting on the *absence* of GET — switched to PATCH which is still unsupported).

### 5. Live verification

| Case | Curl | Status | Notes |
| --- | --- | --- | --- |
| T-1 | `curl -H 'Authorization: Bearer test-secret-token-001' /todo/index` | **200** | Returns admin's TODO list |
| T-2 | `curl -X POST -H 'Authorization: Bearer ...' -d '{"title":"FT16"}' /todo/index` | **200** | Creates TODO, returns new row |
| T-3 | `curl -H 'Authorization: Bearer ...' /todo/item/id_5` | **200** | Returns single TODO |
| T-3b | `curl -H 'Authorization: Bearer ...' /todo/item/id_99999` | **404** | `TODO-NOT-FOUND` envelope |
| T-4 | Login → cookie + CSRF → POST /todo/index | **200** | Browser flow unchanged |
| T-5 | `curl -H 'Authorization: Bearer wrong-token' /todo/index` | **401** | `SESSION-CLOSED` envelope |
| T-6 | `curl /todo/index` (no header) | **401** | Same |
| T-7 | env unset + `curl -H 'Authorization: Bearer ...'` | **401** | Bearer silently ignored, falls through to session check |

Every acceptance criterion green.

### 6. Tests

- `tests/Unit/Xion/BearerAuthTest.php`: 9 cases covering `extractToken` parse (Bearer, case-insensitive, whitespace, trailing newline, empty, Basic-rejection, empty token, whitespace-inside, reset).
- `tests/Unit/Xion/CsrfProtectionPolicyTest.php`: 1 new case (`testDoesNotRequireTokenWhenAuthenticatedViaBearer`).
- `tests/Http/HttpSmokeTest.php`: refactored 1 case (PATCH for 405), added 1 (`testGetTodoItemReturnsSessionClosedWithoutAuth`).

Total: 99 unit / 24 HTTP (1 expected skip).

### 7. OpenAPI

`bearerAuth` security scheme (`type: http, scheme: bearer`) added under `components.securitySchemes`. `/todo/*` operations updated with `bearerAuth: []` as an alternative to `sessionCookie` (read ops) or `sessionCookie + csrfToken` (write ops). New `getTodo` operation under `/todo/item/id_{id}`.

The contract test (`OpenApiRuntimeContractTest`) auto-discovers and probes the new operation; documented response statuses include `200`, `400`, `401`, `404`, `405` so the empty-body probe lands on a documented status.

## Results

| Acceptance criterion (Issue #380) | Status |
| --- | --- |
| `GET /todo/index` → 200 with Bearer | Pass |
| `POST /todo/index` → 2xx with Bearer | Pass |
| `GET /todo/item/id_{id}` → 200 with Bearer | Pass |
| OpenAPI reflects Bearer option | Pass |
| Browser session+cookie flow unchanged | Pass |
| (bonus) Invalid Bearer → 401 SESSION-CLOSED | Pass |
| (bonus) `composer test` 99/99 | Pass |
| (bonus) `composer test:http` 24/24 (1 expected skip) | Pass |

## Friction Summary

| ID  | Location                                       | Severity | Kind            | Decision         |
| --- | ---------------------------------------------- | -------- | --------------- | ---------------- |
| F-1 | `class/xion/BearerAuth.php` (mod_php quirk)    | medium   | informational   | document         |
| F-2 | `class/controller/TodoController.php`          | medium   | feature-gap     | fix-in-framework |
| F-3 | `class/xion/AuthSession.php::bindBearer`       | low      | informational   | document         |
| F-4 | (FT15 F-1 redux — `getenv() ?:` idiom)         | low      | informational   | (no change)      |
| F-5 | `docs/field-trials/README.md`                  | low      | informational   | document         |

## Recommendations

### Immediate (feat PR — one PR, all five acceptance criteria)

1. **ADR-0008 + `BearerAuth` + `AuthSession::bindBearer` + `CsrfProtectionPolicy` signature + `TodoController::itemGetRest` + `UserMapper::findRowByUserIdentifier` + `htdocs/index.php` wire + OpenAPI updates + compose env + tests.** Lands together — the acceptance criteria need every piece.

### Immediate (docs PR)

1. **`docs/development/agent-bearer-auth.md` (new)**: env matrix, mod_php `Authorization` quirk + fallback recipe, in-memory binding, "future tokens-per-user" extension path, cross-repo handoff trace.
2. **`docs/development/production-deployment.md`** env matrix: two new rows (`NENE_AGENT_BEARER_TOKEN`, `NENE_AGENT_BEARER_USER`).
3. **`docs/api/reference-client.md`**: a short "Bearer for non-browser callers" section pointing at the new doc.
4. **`docs/field-trials/README.md`**: a paragraph on the cross-repo Issue-reuse pattern (F-5).
5. **`AGENTS.md`** Read-First link.

### Cross-repo

Once both PRs merge, **nene-mcp will run their confirmation FT** per Issue #380's body. The confirmation FT validates with their actual catalog (`ft204-persona-business-hard`) that login is no longer needed and writes go through.

### Trade-offs

The Bearer-to-admin mapping is configurable (`NENE_AGENT_BEARER_USER`) but currently maps to a **single** user. Tokens-per-user (each token mapping to a distinct DB row) is the natural extension — a future trial introduces a `personal_access_tokens` table and updates `BearerAuth::lookupUser()` to resolve via token hash instead of env value. Out of scope for FT16 (Issue #380's acceptance criteria don't require it).

## Aftermath

- Trial Issue #380 closes via the feat PR (no new trial issue created — cross-repo trace preserved).
- ADR-0008 lives at `docs/adr/0008-optional-bearer-for-agent-routes.md`.
- nene-mcp side runs their confirmation FT.
- `docs/field-trials/follow-ups.md` does not need an FT16-derived entry.
