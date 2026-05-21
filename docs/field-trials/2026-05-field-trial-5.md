# Field Trial 5 — protected-notes (Auth-protected HTML pages with form CSRF)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #274.

## Date

2026-05-21

## Baseline

- NeNe ref: `9c86e43` (post #262 / #268 / #269 / #270 / #272 / #273 — post-FT4 main, with FT4 docs improvements + bootstrap script with CLAUDE.md skeleton)
- Clone path: `/home/xi/github/NeNe-FT/ft5-protected-notes/` (created via `tools/nene-ft-new.sh protected-notes`)
- Host ports: app=8085, mysql=3312 (auto-offset N=5)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default), SQLite parity verified via `cli/initSQLite.php`
- Other tooling: PHPUnit 10.5.63, Composer 2.9.8, jq 1.6, curl 8

### Baseline verification

| Check | Result |
| --- | --- |
| `tools/nene-ft-new.sh protected-notes` | clone success — but the bootstrap script surfaced F-1 when invoked from the wrong cwd (first attempt created `NeNe-FT/NeNe-FT/ft1-protected-notes` because cwd was an FT4 clone). Re-invoked from framework dir produced the expected `ft5-protected-notes`. |
| `docker compose up -d app` | `/health` `healthStatus=ok` in 1s (after schema recreate with `down -v && up -d`: 7s) |
| `composer test` | 45 / 45, 129 assertions |
| `composer test:http` (NENE_HTTP_BASE_URL=http://127.0.0.1) | 21 / 21, 205 assertions, 1 expected skip |

Baseline itself is clean apart from F-1, which surfaces *before* the trial proper begins. Recorded as a finding because it would block anyone running FT5+ from inside an FT clone.

## Goal

Cross FT3 (Session + CSRF on REST) with FT4 (server-rendered HTML). Specifically test the FT4 F-1 caution: "HTML form CSRF is not enforced by the framework gate — the controller must do it manually."

Surfaces exercised:

1. `SESSION_CHECK = true` (default) behavior for HTML actions when the user is not logged in
2. HTML login form implemented in a custom controller (not the existing REST `SessionController`)
3. HTML form CSRF via hidden field + `AuthSession::verifyCsrfToken()` (no framework gate)
4. HTML logout button (CSRF-protected POST → 302)
5. Session expiry behavior (post-logout HTML access)
6. `LOGOUT_URI` constant behavior and customization

## Service Built — protected-notes

A per-user `PrivateNote` entity (user_id FK to users), HTML CRUD with login required, CSRF required on every state-changing form.

### Schema (parallel changes in two locations)

`docker/mysql/init/001_schema.sql` and `cli/initSQLite.php` both received a `private_notes` table (id, user_id FK to users.id, title VARCHAR(255), body TEXT, created_at, updated_at, is_deleted). SQLite path got the corresponding `private_notes_updated_at_trigger` to mirror MySQL's `ON UPDATE CURRENT_TIMESTAMP`. ON DELETE CASCADE so deleting a user removes their notes.

### Pages

| Method | Path | Handler | Auth | CSRF |
| --- | --- | --- | --- | --- |
| GET | `/auth/login` | `AuthController::loginAction` | public | – |
| POST | `/auth/login` | `AuthController::loginAction` (`$this->method` 分岐) | public | – |
| POST | `/auth/logout` | `AuthController::logoutAction` | (works either way) | manual |
| GET | `/privatenote/index` | `PrivatenoteController::indexAction` | required (default) | – |
| POST | `/privatenote/index` | `PrivatenoteController::indexAction` (POST 分岐) | required | manual |
| GET | `/privatenote/new` | `PrivatenoteController::newAction` | required | – |

`AuthController::preAction()` sets `SESSION_CHECK = false` so the login form is reachable while unauthenticated. `PrivatenoteController` keeps the default (`true`), so the framework's `sessionCheck()` redirects unauthenticated visitors to `LOGOUT_URI`.

### Trial-only config change

`ini/xSystemIni.php` has `const LOGOUT_URI = '/';` hardcoded. With the default, `sessionCheck()` on a protected HTML page redirects to the index splash, which is not where an authenticated app wants its unauthenticated users to land. The trial changed it locally to `'/auth/login'`. This change does not return to the framework — F-2 escalates the constant to environment-overridable.

### Tests

A new `tests/Http/ProtectedNotesHtmlTest.php` covers:
- Unauthenticated GET `/privatenote/index` → 302 → `/auth/login` (not splash)
- GET `/auth/login` → 200 + form fields
- POST `/auth/login` with wrong / empty credentials → 200 + error message re-render
- Full flow: login → list → create-with-CSRF → list reflects → logout → unauth re-confirmed
- POST `/privatenote/index` without CSRF → 403
- POST `/auth/logout` without CSRF → 403
- GET `/auth/logout` → 302 (defensive guard, no actual logout)

Suite totals after FT5:
- `composer test`: 45 / 45, 129 assertions (no regression)
- `composer test:http`: 29 / 29, 259 assertions, 1 expected skip (21 existing + 8 new)

## Steps Taken

1. **Trial clone bootstrap** via `tools/nene-ft-new.sh protected-notes`. First attempt failed (F-1: ran from FT4 clone dir, created `NeNe-FT/NeNe-FT/ft1-protected-notes`). Cleaned up via `find -delete` and re-ran from framework dir, producing the correct `ft5-protected-notes`.
2. **Baseline verified**.
3. **Read auth surface**: `SessionController` (REST-only login/logout), `AuthSession` (login/logout/isLoggedIn/userId/csrfToken/verifyCsrfToken — clean public API, F-10 positive), `ControllerBase::sessionCheck()` (`final` — F-3), `LOGOUT_URI = '/'` (hard const — F-2). Found that `sessionCheck()` ALREADY handles REST/HTML split correctly (REST → JSON 401, HTML → location(LOGOUT_URI)) — H-B / H-F unfired (F-9 positive).
4. **LOGOUT_URI overridden in trial** to `/auth/login`. Recorded as F-2.
5. **Schema + Model + Mapper** added (`PrivateNote`, `PrivateNoteMapper` per-user scoped — same shape as FT3's MemoMapper).
6. **AuthController + PrivatenoteController** written:
   - Initially tried URL `/private-note/index` → 404 because dispatcher's `ucfirst(strtolower($controller))` produces invalid `Private-noteController`. Surfaced as F-5. Renamed to `/privatenote/index` + `PrivatenoteController`.
   - `logoutAction()` needs a `$this->method !== 'POST'` guard (F-6, FT4 F-1 extension).
   - CSRF on HTML forms requires three manual steps (controller sets `t_csrf_token`, template emits hidden field, handler calls `verifyCsrfToken`) — F-4.
7. **Templates + assets** written. Layout extension + setTitle + asset auto-discovery worked first try, same DX as FT4.
8. **Manual smoke** verified all paths (unauth → redirect, login, list with CSRF token, create with CSRF, logout, post-logout redirect).
9. **HTTP test** written. Initial cookie jar bug (read first PHPSESSID Set-Cookie instead of last) caused 3 failures — `session_regenerate_id(true)` on login emits two Set-Cookie headers and the second is the active session id. Fixed and recorded as F-8.
10. **Suite green** under both unit (45 / 45, 129) and HTTP (29 / 29, 259, 1 expected skip).

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `tools/nene-ft-new.sh` from arbitrary cwd | creates `ft{N}-{topic}` under `../NeNe-FT/` | created wrong nested dir from FT4 clone cwd | F-1 |
| Unauthenticated GET on protected HTML page | 302 redirect to login (not splash) | works after F-2 workaround (LOGOUT_URI override) | Pass (with F-2 / F-3) |
| HTML login form display + login POST + redirect | 302 to protected page | works | Pass (with F-7 docs gap) |
| HTML form with CSRF hidden field | server accepts request | works after F-4 three-step manual setup | Pass (with F-4) |
| HTML form POST without CSRF | 403 | works | Pass |
| HTML logout POST with CSRF | 302 + session terminated | works | Pass |
| HTML logout POST without CSRF | 403 | works | Pass |
| GET on `/auth/logout` | 302 to login (no side effect) | works after F-6 defensive guard | Pass (with F-6) |
| Hyphenated URL `/private-note/index` | resolves to `PrivateNoteController` | 404 — dispatcher can't form valid class name | F-5 |
| Session regenerate on login | client keeps active session id | works only after picking the LAST Set-Cookie, not the first | F-8 |
| `composer test` regression | 45 / 45 | yes | Pass |
| `composer test:http` | all pass | 29 / 29, 259, 1 expected skip | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `tools/nene-ft-new.sh` cwd-dependent FRAMEWORK_ROOT detection | medium | feature-gap | fix-in-framework |
| F-2 | `ini/xSystemIni.php` `LOGOUT_URI` is hard `const`, no env override | medium | feature-gap | fix-in-framework |
| F-3 | `class/xion/ControllerBase::sessionCheck()` is `final`, no per-controller override hook | low | design-trade-off | document |
| F-4 | HTML form CSRF requires three manual steps; REST/HTML asymmetry | medium | feature-gap | document + (optional helper) |
| F-5 | Hyphenated URL → controller class resolution breaks (`private-note` → invalid class name) | low | docs-gap | document |
| F-6 | HTML side-effect actions have no POST-only dispatch; must guard `$this->method` | low | docs-gap | document (consolidate with FT4 #263 follow-up) |
| F-7 | HTML login form has no reference implementation or tutorial section | medium | docs-gap | document |
| F-8 | `session_regenerate_id(true)` on login emits two `Set-Cookie: PHPSESSID=` headers; external clients must keep the LAST | low | docs-gap | document (add to `docs/api/reference-client.md`) |
| F-9 | `sessionCheck()` already handles REST vs HTML split correctly (HTML → redirect) | n/a (positive) | none | no action |
| F-10 | `AuthSession` public API (login / logout / isLoggedIn / userId / csrfToken / verifyCsrfToken) is clean and usable directly from HTML controllers | n/a (positive) | none | no action |

### Hypotheses outcome

| # | Hypothesis | Materialized? |
| --- | --- | --- |
| H-A | HTML login form custom controller pattern undocumented | yes (F-7) |
| H-B | `SESSION_CHECK=true` behavior on HTML pages unclear | **no** — framework redirect mechanism exists (F-9); but redirect target customization is hard (F-2 / F-3) |
| H-C | HTML form CSRF is manual | yes (F-4) |
| H-D | `AuthSession::login()` `$user` shape undocumented | partial — pass `findByCredentials()` row directly works; explicit shape never documented |
| H-E | Logout button also needs CSRF | yes (F-4 / F-6) |
| H-F | Session expiry HTML UX is poor | **no** — redirect path exists (F-9), poor UX only because LOGOUT_URI defaults to splash (F-2) |

## Recommendations

### Immediate (documentation only)

1. **F-5** — Add a short note to `docs/tutorials/building-a-service.md` (and possibly `docs/development/coding-standards.md`): URL controller segments must be a single lowercase word (`privatenote`), not kebab-case (`private-note`), because the dispatcher uses `ucfirst(strtolower(...))` to form the class name and PHP class names cannot contain hyphens. The "right" long-term fix is dispatcher kebab→PascalCase auto-conversion, but the documentation closes the immediate footgun.
2. **F-6** — Append to the FT4 #263 follow-up (HTML form POST tutorial section): also cover the "side-effect action needs `$this->method !== 'POST'` guard" pattern. Same Issue if still in flight, else a new docs PR.
3. **F-7** — Add an "Add an HTML login form" subsection to the same tutorial. Use the FT5 `AuthController` as the reference example. Cross-link from "Add Authentication Requirements" (line 444 of the tutorial).
4. **F-8** — Add to `docs/api/reference-client.md` (FT3 PR #254) a short note: "Login responses emit two `Set-Cookie: PHPSESSID=...` headers due to `session_regenerate_id(true)`. Keep the last one. Most HTTP libraries handle this automatically; bare `file_get_contents` / hand-written clients do not." Also document that `AuthSession::login()` accepts the row returned by `UserMapper::findByCredentials()` directly.

### Suggested (small framework or template change)

5. **F-2** — Make `LOGOUT_URI` env-overridable:
   ```php
   const LOGOUT_URI = (getenv('NENE_LOGOUT_URI') !== false) ? (string)getenv('NENE_LOGOUT_URI') : '/';
   ```
   Plus a docs note in `docs/development/docker.md` (alongside other `NENE_*` env vars) and the README. No behavior change for default deployments.
6. **F-4** — Add a thin helper to `ControllerBase` that compresses the three manual CSRF steps:
   ```php
   final protected function csrfTokenForView(): string { return $this->AUTH_SESSION->csrfToken(); }
   final protected function verifyCsrfFromPost(string $field = 'csrf_token'): bool {
       return $this->AUTH_SESSION->verifyCsrfToken((string)($this->request->getPost($field) ?? ''));
   }
   ```
   And/or a Smarty plugin `{csrf_field}` that emits the hidden input. Optional but lowers the per-form boilerplate to 1+1 lines.

### Trade-offs (needs ADR or discussion)

7. **F-3** — `sessionCheck()` is `final`. To allow per-controller redirect targets (e.g. `/admin/login` vs `/auth/login`), either drop the `final` modifier or add a hook (`protected function unauthorizedRedirect(): string { return LOGOUT_URI; }`). The latter keeps the dispatch invariant. ADR-class because it changes the inheritance contract.

### Bootstrap script bug

8. **F-1** — `tools/nene-ft-new.sh` needs a FRAMEWORK_ROOT sanity check. Add at the top:
   ```sh
   if [ ! -f "$FRAMEWORK_ROOT/class/xion/ControllerBase.php" ]; then
       echo "ERROR: this script must run from the framework repo, not from a clone. cwd: $FRAMEWORK_ROOT" >&2
       exit 1
   fi
   ```
   Recommend including a clearer next-step message ("cd /path/to/NeNe && tools/nene-ft-new.sh ...").

### Confirmed working (no action)

9. **F-9 / F-10** — `sessionCheck()` REST/HTML split + `AuthSession` public API are well-formed and let a contributor build a protected HTML page without ad-hoc framework patches. The pieces are there; FT5's friction was about *finding* the pieces and customizing their defaults.

## Overall Impression

FT5 is the cleanest "filling in the matrix" trial so far — FT3 covered auth on REST, FT4 covered HTML rendering, and FT5 multiplies them. The matrix held up: every required piece (HTML redirect on session miss, `AuthSession` public API for HTML controllers, CSRF token retrieval, form POST dispatch) exists in the framework. The friction surface is entirely about *customization* and *discoverability*:

- The framework defaults `LOGOUT_URI` to `/` and makes it impossible to override without editing source (F-2). This is the single biggest UX paper cut for any app that has a custom login page (which is most apps).
- HTML form CSRF works but requires a three-step ritual the developer must remember (F-4). No framework helper.
- The HTML login flow has no tutorial reference (F-7); developers reverse-engineer it from `SessionController` (REST) + `AuthSession` API docs.

The pleasant surprises (F-9, F-10) were that the framework's auth/session boundaries already anticipated HTML usage — `sessionCheck()` doesn't try to send JSON 401 to a browser, it redirects. The maintainer thought about both paths even though only REST got a sample controller.

The bootstrap script bug (F-1) is unrelated to the trial's main surface but surfaced first because FT5 was the first trial after FT4 to use the script. The same bug would have hit FT4 if the user had run `tools/nene-ft-new.sh` from inside the FT3 clone.

What FT5 did not exercise but expected to: i18n for HTML labels, multi-tenant scoping beyond per-user, password reset / forgot-password flow. These are likely FT6+ territory if the framework grows in that direction.

## Follow-up Issues

To be filed under the FT5 loop (close all before starting FT6):

- F-1 (medium, framework): bootstrap script FRAMEWORK_ROOT sanity check.
- F-2 (medium, framework): `LOGOUT_URI` env override.
- F-3 (low, framework + ADR): `sessionCheck()` overridability.
- F-4 (medium, framework + docs): HTML form CSRF helper + tutorial pattern.
- F-5 (low, docs): URL controller naming convention note.
- F-7 (medium, docs): HTML login form reference / tutorial section.
- F-8 (low, docs): reference-client.md addendum (session_regenerate_id behavior + login row shape).

F-6 is consolidated into the FT4 #263 follow-up (already covers "actionAction is HTTP-method-blind, guard side effects") — adding a back-reference is enough.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
