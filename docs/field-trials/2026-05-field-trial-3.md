# Field Trial 3 — authlog (Session + CSRF + auth-protected REST)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #245.

## Date

2026-05-21

## Baseline

- NeNe ref: `77c1909` (Merge PR #244, the FT2 DomainException feature). The clone landed on the same `main` after the FT2 follow-up PRs (#241, #242, #243, #244) were all merged.
- Clone path: `/home/xi/github/NeNe-FT/ft3-authlog/`
- Trial host: WSL 2 (Ubuntu 22.04) on Windows with Docker Desktop integration enabled.
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default), SQLite parity verified via `cli/initSQLite.php`.
- Other tooling: PHPUnit 10.5.63, Composer 2.9.8, curl 8.

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | clean, no warnings |
| `docker compose up -d app` | `/health` 200 OK, `databaseType: MySQL` |
| `composer test` | 45 / 45, 129 assertions |
| `composer test:http` with `NENE_HTTP_BASE_URL` set | 21 / 21, 220 assertions; 1 expected skip is `HttpErrorExposureTest` |

All FT1 and FT2 improvements continue to work. No baseline friction recorded for FT3.

## Goal

Exercise the auth-protected REST surface that FT1 (bookmarklog) and FT2 (bookmark+tag) deliberately skipped:

1. `SessionController::loginPostRest` / `logoutPostRest` — session cookie issuance, CSRF token in the response, logout.
2. `SESSION_CHECK = true` default behavior returning 401 (`SESSION-CLOSED`) for unauthenticated calls.
3. `requiresCsrfProtection()` policy — state-changing API + logged in → require `X-CSRF-Token` header.
4. End-to-end client flow: login → keep cookie + csrf token → CRUD → logout → cookie invalidated.
5. `AuthSession::isLoggedIn()` / `userId()` / `logout()` integration.

A single per-user entity `Memo` was built as the vehicle. The entity itself is intentionally trivial; authentication is the surface under test.

## Service Built — authlog

### Schema (parallel changes in two locations)

`docker/mysql/init/001_schema.sql` and `cli/initSQLite.php` both received a `memos` table:

- `id` PK
- `created_at`, `updated_at`
- `user_id` FK → `users(id)` ON DELETE CASCADE
- `body` (text up to ~64KB)
- `is_deleted` (soft delete)

SQLite path includes a trigger to mirror MySQL's `ON UPDATE CURRENT_TIMESTAMP`, following the convention FT2 documented in `docs/development/docker.md`.

### Endpoints

| Method | Path | Handler | Auth |
| --- | --- | --- | --- |
| GET | `/memo/index` | `indexGetRest` | session |
| POST | `/memo/index` | `indexPostRest` | session + CSRF |
| GET | `/memo/item/id_X` | `itemGetRest` | session |
| PATCH | `/memo/item/id_X` | `itemPatchRest` | session + CSRF |
| DELETE | `/memo/item/id_X` | `itemDeleteRest` | session + CSRF |

`MemoController` does **not** override `SESSION_CHECK` — the framework default (`true`) is what protects every route. CSRF is enforced automatically when logged in for the four state-changing methods (`POST`, `PUT`, `PATCH`, `DELETE`), driven by `CsrfProtectionPolicy` via `ControllerBase::requiresCsrfProtection()`. The controller code itself contains no CSRF or session logic.

### Error catalog

Three new codes were added to `config/error_codes.php`: `MEMO-ID-REQUIRED`, `MEMO-NOT-FOUND`, `MEMO-BODY-REQUIRED`.

### OpenAPI

`docs/api/openapi.yaml` grew by ~210 lines covering 5 operations, 11 components (3 per-error-code envelopes, 3 success envelopes, 1 entity schema, 2 request body schemas, 1 path parameter, 1 reusable response). FT3 followed the **per-error-code envelope** pattern used by the existing TODO contract for consistency. See F-1.

### Tests

A new `tests/Http/MemoAuthTest.php` covers:

- Unauthenticated GET / POST → 401 SESSION-CLOSED.
- Login response shape: 200 + cookie attributes (HttpOnly, SameSite=Lax) + csrfToken.
- POST without X-CSRF-Token → 403 CSRF-TOKEN-INVALID.
- Full lifecycle: create → list → detail → patch → delete → 404 after delete.
- Validation failures (empty body, missing/invalid id).
- Logout → subsequent request with same cookie → 401.

`tests/Http/OpenApiRuntimeContractTest.php` was extended with 5 new probe tuples to cover the Memo operations.

Suite totals after FT3:

- `composer test`: 45 / 45, 129 assertions (no regression).
- `composer test:http`: 32 / 32, 393 assertions, 1 expected skip.

## Steps Taken

1. **Trial clone created** at `../NeNe-FT/ft3-authlog/`. No friction.
2. **Baseline runtime verified**. All FT1+FT2 improvements work.
3. **Schema added in two files** (MySQL + SQLite). Verified parity by inspecting `/health`. The parallel-maintenance note added by FT2 (PR #240) was the relevant doc.
4. **Model + Mapper written** (`Memo`, `MemoMapper`). `MemoMapper` extends `DataMapperBase` and exposes per-user scoped helpers (`findRowsByUserId`, `findRowByUserIdAndId`, `createForUser`, `updateForUser`, `deleteForUser`) — same shape as `TodoMapper`. No friction.
5. **Controller written** (`MemoController`). No `SESSION_CHECK` override, no CSRF code. The framework defaults from `ControllerBase` and `CsrfProtectionPolicy` cover both. The controller is purely entity logic; auth is invisible to it.
6. **Smoke verified manually** via curl: 401 without session, 200 + csrfToken on login, 403 without CSRF header on POST, 201 with CSRF, list reflects the create. All as predicted.
7. **HTTP test written**. The existing `tests/Http/HttpClient` already handles cookie jar and auto-injects `X-CSRF-Token` on non-GET requests by capturing `Data.csrfToken` from any response, so the test code never mentions CSRF or cookie management — see F-3 (this is good for our tests, bad for external consumers).
8. **OpenAPI extended**. 5 endpoints required adding ~210 lines, of which 3 per-error-code envelopes (`MemoIdRequiredEnvelope`, `MemoNotFoundEnvelope`, `MemoBodyRequiredEnvelope`) are mechanical mirrors of the existing TODO envelopes — see F-1.
9. **OpenAPI contract test extended**. `OpenApiRuntimeContractTest` enumerates probe tuples by hand; FT3 added 5 new tuples manually. See F-2.
10. **Suite green** under both unit (45 / 45, 129) and HTTP (32 / 32, 393, 1 expected skip).

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Default `SESSION_CHECK` blocks unauth GET | 401 SESSION-CLOSED | Works | Pass |
| Default `SESSION_CHECK` blocks unauth POST | 401 SESSION-CLOSED (not 403) | Works (session check runs before CSRF check) | Pass |
| Login returns csrfToken in `Data.csrfToken` | Non-empty string | Works | Pass |
| Login sets PHPSESSID with HttpOnly + SameSite=Lax | Present in Set-Cookie | Works | Pass |
| POST without X-CSRF-Token while logged in | 403 CSRF-TOKEN-INVALID | Works | Pass |
| Full CRUD lifecycle with session + CSRF | 200 throughout, 404 after delete | Works | Pass |
| Validation failures (empty body, bad id) | 400 with catalog code | Works | Pass |
| PATCH on missing memo | 404 MEMO-NOT-FOUND | Works | Pass |
| Logout → GET with same cookie | 401 SESSION-CLOSED | Works | Pass |
| OpenAPI contract test for 5 new operations | All paths probed; documented statuses | Works after manual tuple addition | Pass (with F-2) |
| `composer test:http` | All pass | 32 / 32, 393, 1 skip | Pass |
| `composer test` regression | 45 / 45 still | Yes | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `docs/api/openapi.yaml` per-error-code envelope schemas | low | feature-gap | fix-in-framework (escalation of FT2 F-5) |
| F-2 | `tests/Http/OpenApiRuntimeContractTest.php` hardcoded probe tuples | low | feature-gap | fix-in-framework |
| F-3 | `docs/api/README.md` / `docs/tutorials/building-a-service.md` Reference Client guidance | medium | docs-gap | document |
| F-4 | Auth onboarding from the tutorial | n/a (positive) | none | no action |

Note on F-1: FT2 followed-up entry F-5 (per-error-code envelope boilerplate) listed its re-evaluation trigger as "a third entity gets added to the OpenAPI contract and the decision repeats". FT3 added `Memo` (a third entity after TODO and the FT2 bookmark/tag pair) and again paid the boilerplate cost. The trigger has fired; F-1 escalates F-5 to an ADR-class decision.

## Recommendations

### Immediate (documentation only)

1. **F-3** — Add a "Reference Client" subsection to `docs/api/README.md` describing the four mechanics an external consumer must implement: (a) capture `Set-Cookie: PHPSESSID=...` from `/session/login`; (b) send `Cookie: PHPSESSID=...` on every subsequent request; (c) capture `Data.csrfToken` from `/session/login` (or any response that refreshes it); (d) inject it as `X-CSRF-Token: ...` on `POST` / `PUT` / `PATCH` / `DELETE`. The tutorial covers (c) and (d) partially; (a) and (b) are implicit today. A 30-line example client (curl or a tiny `fetch` wrapper) would close the gap.

### Suggested (small framework or template change)

2. **F-2** — Make `OpenApiRuntimeContractTest::testDocumentedRestOperationsRespondWithDocumentedStatuses` self-discovering. The current shape requires editing the probe array every time a new endpoint is added. A discovery loop over `$openApi['paths']` exercising each operation with a synthetic body derived from the documented `requestBody.example` would automatically extend coverage to any future endpoint. The trade-off is that operations with non-trivial preconditions (e.g. requiring a real id) need an opt-out marker or per-path setup hook. FT3 added 5 probe tuples manually; the marginal cost is small now but compounds.

### Trade-offs (needs ADR or discussion)

3. **F-1 (escalation of FT2 F-5)** — Two options remain on the table:
   - (a) Keep per-error-code envelopes as canonical. Strengths: each error code is individually documented and validatable; consumers reading the OpenAPI see the exact code + message constant. Costs: each new error code adds ~12 lines of mechanical envelope schema; growth is linear in error codes, not entities.
   - (b) Adopt a single generic `ApiFailureEnvelope` (as FT2 used internally). Strengths: contract shrinks; new endpoints add only request/success envelopes. Costs: consumers lose the per-code documentation; documentation has to move to a separate code reference (which `docs/development/error-codes.md` could host).
   This is an ADR-class decision because it changes a published contract shape. FT3 will file an Issue requesting the ADR; until then per-code envelopes remain the convention.

### Confirmed working (no action)

4. **F-4** — The auth onboarding from `docs/tutorials/building-a-service.md` was complete. The tutorial covers (i) `SESSION_CHECK = true` as the default (line 446), (ii) the four state-changing methods that require CSRF (line 189), (iii) the login → read csrfToken → send `X-CSRF-Token` flow (lines 454–458). Building `MemoController` required no extra reading of `ControllerBase`, `AuthSession`, or `CsrfProtectionPolicy`. The hypotheses about CSRF/SESSION_CHECK docs gaps recorded in `FT3-PLAN.md` (H-A, H-B, H-C, H-F) were wrong.

## Overall Impression

FT3 was the smoothest trial so far. The auth defaults — `SESSION_CHECK = true`, automatic CSRF on state-changing methods when logged in, session-check ordering before CSRF check — meant that building `MemoController` required zero auth-specific code. The controller is pure entity logic. The framework's "secure by default, opt out only when public" choice paid off for ergonomics.

The two findings that matter long-term are documentary or structural:

- **F-3** is the only finding that affects external consumers directly. The tutorial assumes the reader's HTTP client already handles cookies and CSRF retention. NeNe's own test client (`tests/Http/HttpClient`) handles both transparently, but consumers building from scratch (e.g. a third-party Go or Python client) need the four mechanics spelled out. A short Reference Client section closes the loop.
- **F-1** is the third sighting of the OpenAPI per-error-code envelope cost. FT2 deferred it as a one-off; FT3 confirms it as a pattern. Either keep the canonical shape and accept that documenting N error codes costs ~12N lines of YAML, or migrate to a generic shape and own the documentation gap that creates. An ADR is the right venue.

F-2 (hardcoded contract test) is a small quality-of-life fix that becomes more valuable as the spec grows. FT3 caught it cleanly because adding 5 new tuples doubled the probe list.

What FT3 did **not** surface but expected to: H-A through H-F all proved to be non-issues. The tutorial is more complete than the hypothesis assumed. This is encouraging — the FT loop is now mostly catching small framework refinements rather than onboarding cliffs.

## Follow-up Issues

To be filed under the FT3 loop (close all before starting FT4):

- F-1 (low, framework + ADR): OpenAPI per-error-code envelope shape decision. Escalates FT2 F-5.
- F-2 (low, framework): Make `OpenApiRuntimeContractTest` self-discovering.
- F-3 (medium, docs): Add a Reference Client section to `docs/api/README.md`.

F-4 is positive (no action). No FT3 finding is being deferred; `docs/field-trials/follow-ups.md` is updated to remove the now-escalated FT2 F-5 entry.

Next trial candidate: FT4 — Smarty HTML rendering path, which FT1 / FT2 / FT3 all skipped.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
