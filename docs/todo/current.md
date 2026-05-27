# Current TODO

This file summarizes short-term work for humans and AI agents. GitHub Issues remain the source of truth for actionable work.

## Active

No open Issues. Promotion articles (#178 Qiita / #179 DEV Community / #180 Reddit・HN) are closed — outreach is managed in a separate repository.

The Phase 6 (reviewable small-service delivery) and Phase 7 (field trials) loops have been running continuously. As of 2026-05-27: all non-promotion Issues are closed; FT1–FT50 are complete (FT36 deferred as ADR-class); ADR-0001–0013 are in place. Next work is FT51+ — see **Field Trials** and **Backlog Candidates** below.

## Recently Completed

### 2026-05-27 — FT25–FT50: NENE2 parity wave + extended patterns (26 trials)

Single-day wave of 26 field trials covering NENE2-equivalent patterns plus additional production-readiness helpers. All PRs #458–#482 (pending merge).

**FT25 — Cursor pagination**: `Cursor` + `CursorPage`; base64url token; keyset SQL (created_at, id). PR #458.
**FT26 — Soft delete**: `DataMapperBase::SOFT_DELETE`; softDelete/restore/findTrashed/purge; deleted_at auto-filter. PR #459.
**FT27 — Optimistic locking / ETag**: `OptimisticLock`; parseIfMatch/requireVersion/conflict; 412/428. PR #460.
**FT28 — Rate limiting**: `RateLimiter` + Redis storage; fixed-window INCR+EXPIRE; X-RateLimit-* headers; 429. PR #461.
**FT29 — State machine**: `WorkflowDefinition`; code-driven transition map; assertTransition → 409. PR #462.
**FT30 — JWT HS256**: `JwtCodec`; pure PHP HMAC-SHA256; issue/decode/require; alg:none defence. PR #463.
**FT31 — RBAC**: `RoleGuard`; JWT claims-based require/requireAny/has; 401 vs 403. PR #464.
**FT32 — Password reset**: `PasswordResetToken`; random_bytes + SHA-256; isExpired/expiresAt. PR #465.
**FT33 — Audit log**: `AuditLogger`; append-only audit_log; PDO injection; silent on PDOException. PR #466.
**FT34 — Webhook signing**: `WebhookSigner`; Stripe-style t=ts,v1=hmac; hash_equals; generateSecret(). PR #467.
**FT35 — Feature flags**: `FeatureFlagService`; DB-backed; user override → global → rollout%; crc32 bucket. PR #468.
**FT37 — Idempotency keys**: `IdempotencyStore`; INSERT IGNORE/OR IGNORE; X-Idempotency-Key / X-Idempotent-Replayed. PR #469.
**FT38 — Full-text search**: `SearchQuery`; escapeLike/likePattern/sanitizeFts/normalize; FTS5 doc. PR #470.
**FT39 — API versioning / deprecation**: `ApiDeprecation`; RFC 8594 Deprecation/Sunset/Link; ADR-0013. PR #471.
**FT40 — Batch operations**: `BatchResult`; addSuccess/addFailure/httpStatus; 200/207/422 partial-success. PR #472.
**FT41 — Account lockout**: `LoginAttemptTracker`; DB-backed failure counter; locks at threshold; ACCOUNT-LOCKED 423. PR #473.
**FT42 — Signed URL**: `SignedUrl`; HMAC-SHA256 sign/verify/requireValid; expiry; SIGNED-URL-EXPIRED 410. PR #474.
**FT43 — Circuit breaker**: `CircuitBreaker`; CLOSED/OPEN/HALF-OPEN state machine; DB-backed; CIRCUIT-OPEN 503. PR #475.
**FT44 — HTTP cache headers**: `HttpCache`; sendCacheControl/sendLastModified/isNotModified/send304; conditional GET. PR #476.
**FT45 — CORS**: `Cors`; sendHeaders/handlePreflight/isAllowed; wildcard vs explicit origins. PR #477.
**FT46 — File upload**: `FileUpload`; require/load/validateSize/validateMime/moveTo; finfo MIME detection. PR #479.
**FT47 — Tree helper**: `TreeHelper`; build/ancestors/descendants/depth/flatten for adjacency-list trees. PR #478.
**FT48 — Offset pagination**: `OffsetPage` + `PaginationHelper`; page envelope; window() UI helper. PR #480.
**FT49 — Money value object**: `Money`; immutable integer-based; add/subtract/multiply/round/format (JPY/USD/EUR). PR pending.
**FT50 — Input validation**: `Validator`; required/maxLength/minLength/email/url/integer/in/regex; VALIDATION-FAILED 422. PR pending.

FT36 (background jobs) deferred as ADR-class — trigger: real "POST takes 8s" friction event.

### 2026-05-21 — FT3 / FT4 / FT5 / FT6 + infra + checklists

Single-day wave of trial-driven improvements across the framework, documentation, and process.

**FT3 (authlog — REST auth + CSRF):** report PR #250; follow-ups #251–#253 → PR #254 (Reference Client docs), PR #255 (self-discovering contract test), PR #256 (ADR-0003 + generic `ApiFailureEnvelope` migration). All Issues closed.

**FT4 (smarty-html — server-rendered HTML pages):** report PR #262; follow-ups #263–#267 → PR #268 (compile cache tip), #269 (`location()` URI normalize), #270 (asset auto-discovery convention), #272 (Smarty escape × `nl2br`), #273 (HTML form POST tutorial section). All Issues closed.

**FT5 (protected-notes — auth × HTML cross):** report PR #275; follow-ups #276–#282 → PR #283 (bootstrap script sanity check), #284 (`LOGOUT_URI` env override), #285 (reference-client.md session-regen note), #286 (URL controller naming docs), #287 (ADR-0004 + `unauthorizedRedirect()` hook), #288 (CI health-wait timeout), #289 (HTML form CSRF helper), #290 (HTML login form tutorial). All Issues closed.

**FT6 (cli-tooling — installer scripts):** report PR #293; follow-ups #294–#298 → PR #299 (`composer setup` shortcut), #300 (`--env=PATH` strict), #301 (`initSQLite.php` `--yes` / `--help`), #302 (schema 3-way parity docs), #303 (canonical / legacy CLI docs + new `docs/development/cli.md`). All Issues closed.

**Process import:** PR #291 added `docs/review/` self-review checklists (8 files: REST controller, HTML controller, database, OpenAPI contract, docs/ADR, release/CI, field-trial report, README index) adapted from sibling NENE2's pattern. Referenced from `docs/workflow.md` and `docs/CONTRIBUTING.md`.

**Stale Issue cleanup:** #234 (FT2 trial Issue, historically open), #145 (AI-readable reference implementation goal), #165 (reviewable Controller-Service-Mapper proof) — closed with explanatory comments. The goals these Issues encoded were effectively delivered through the FT3–FT6 tutorial additions, the `docs/review/` checklists, ADR-0003, and ADR-0004.

**Infra changes that landed alongside the trial loops:**

- `tools/nene-ft-new.sh` — one-shot FT clone bootstrap (port offset, `.claude/settings.local.json`, `.claude/CLAUDE.md`, PLAN skeleton). Sanity check (PR #283) blocks the "run-from-clone-cwd" footgun.
- `field-trial` GitHub label — created and applied retroactively to 18 historical Issues.
- `main` branch protection — required status checks (`unit`, `HTTP runtime smoke (Docker)`); the improvement loops were merged via `gh pr merge --auto`.
- CI workflow — health-wait now requires `Data.healthStatus = ok` (not just HTTP 200) and the timeout is 120s (PR #259 / #288).
- `~/.claude/settings.json` (developer-side) — broad dev-tool wildcards replaced the narrow per-command permission accumulation that had grown in `NeNe/.claude/settings.local.json`.
- `jq` installed on the development host.

### Earlier 2026-05

- #217: Add `?self` type to singleton `$instance` in 6 classes; add `: void` to `IndexController::indexAction()`; fix `@return` PHPDoc in `Dispatcher`.
- #212: Add native type declarations to all properties in remaining `class/xion/` base classes (ModelBase, DataMapperBase, DataModelBase, RouteContext, TransactionManager, ApiResponse, Log, ErrorCode); propagate `array` type to `Todo`/`User` subclass `$schema`.
- #213: Add `: never` to `__clone()` in 6 singleton classes, `: void` to `PdoConnection::__destruct()`, `: mixed` to `DataModelBase::__get()`.
- #207: Add native type declarations to all properties in `ControllerBase`; move `$TITLE`/`$HEADER_TITLE` initialization to constructor.
- #206: Replace `file_put_contents` in `ModelBase::accessLog()` with `$this->LOGGER->info()` to unify logging via Monolog.
- #205: Add `(string)` casts to `preg_replace` in `DataMapperBase`; add missing `: void`, `: mixed`, `: static` return types across `xion/` base classes.
- #201: Further reduce Phan baseline from 13 to 6 issues; fix DataMapperBase::update() bug using isValid() instead of validate() in error message.
- #199: Add return type declaration to preAction() overrides in IndexController and SessionController.
- #195: Update actions/checkout from v4 to v6 in CI workflow.
- #193: Remove dead `$controller` and `$action` properties from `ControllerBase`.
- #190: Clean up `.gitignore`, Vue.js comment in `View`, and completed Issues in `roadmap.md`.
- #189: Improve type declarations and reduce Phan baseline in `class/xion/`.
- #187: Remove the controller-level Smarty template fallback.
- #185: Document Smarty template, CSS, and JavaScript placement conventions.
- #177: Prepare the Zenn renovation-story article.
- #176: Prepare Composer and Packagist-facing metadata for public discovery while keeping `git clone` as the recommended install path.
- #174: Clarify that `git clone` is the recommended install path for now.
- #172: Clarify that the review-cost message is about implementation-style variance, not outside reviewer scarcity.
- #164: Update the public entry to present NeNe as a reviewable small-service PHP framework.
- #169: Position the publication strategy document as a public OSS release case study.
- #167: Add the review-cost angle around modern pattern learning and implementation-style variance.
- #163: Reframe the next phase around reducing code review cost through consistent implementation conventions.
- #162: Document the publication and outreach strategy for NeNe after `v0.2.0`.
- #160: Document AI self-review checklists and service-layer implementation standards.
- #158: Prepare the `v0.2.0` release notes and runtime version.
- #154, #155, #156: Clean up release-blocking code quality concerns in `ControllerBase` and `DataMapperBase`.
- #152: Add the canonical transaction pattern to the sample page tutorial.
- #150: Document the canonical transaction pattern for service tutorials and coding standards.
- #148: Add a database transaction boundary for multi-step mapper work.
- #135: Refactor `ControllerBase` responsibilities into a testable CSRF protection boundary.
- #144: Adjust Phase 6 around reference implementations and small-service delivery.
- #142: Document AI readability and small-service delivery as the next project phase.
- #140: Clarify Docker development database credentials for phpMyAdmin and MySQL.
- #138: Add phpMyAdmin with the darkwolf theme to the Docker development environment.
- #136: Change the project license to MIT.
- #133: Document the `v0.1.0` release milestone and prepare the first framework tag.
- #131: Show the runtime environment label in the development health check card.
- #129: Show the database type in the development health check card.
- #127: Remove the tracked generated SQLite database artifact.
- #125: Clarify the SQLite3 initialization command in install documentation.
- #123: Load the repository-root `.env` before web runtime initialization.
- #121: Add server install database setup CLI and runtime health check.
- #120: Add a traditional Apache/PHP server install guide and public documentation page.
- #116: Expand HTTP runtime coverage for explicit routing, REST method boundaries, and JSON-only responses.
- #108: Remove legacy JSONP output and move JSON handling to the response boundary.
- #106: Update roadmap and milestones to reflect current status and architecture policy.
- #104: Clarify NeNe's renovation philosophy and target audience.
- #102: Add a service implementation tutorial for pages, REST endpoints, database-backed features, OpenAPI, and tests.
- #99: Prepare documentation/sample sections for the home side menu: `Authentication`, `Routing Guide`, and `OpenAPI`.
- #98: Refresh TODO from roadmap, milestones, and Issue state.
- #88: Fix request variable storage and add boundary tests.
- #87: Decide and apply non-200 HTTP status policy for authentication failures.
- #86: Route Dispatcher errors through the shared JSON error responder.
- #85: Safely encode template data-object JSON for inline script output.
- #84: Harden legacy callback output before the JSON-only policy superseded it.
- #83: Add CSRF protection to cookie-authenticated state-changing APIs.
- #82: Hash stored passwords and clean up sample credentials.
- #81: Harden authentication session lifecycle and cookie attributes.
- #80: Control public error display by environment.
- #78: Parse OpenAPI runtime contract tests with `symfony/yaml`.
- #76: Add PHP CS Fixer configuration and formatting scripts.
- #74: Add Phan baseline and repeatable static analysis configuration.
- #70: Document the OpenAPI runtime contract test parser policy.
- #68: Update the SQLite initializer so the TODO sample also works with SQLite fallback.
- #66: Expand first-reader comments in `ini/xSystemIni.php`.
- #64: Organize `ini/xSystemIni.php` constants, runtime definitions, ordering, and comments.
- #62: Remove unused legacy styles from `htdocs/css/common.css`.
- #60: Simplify `view/source` for the React sample layout.
- #58: Remove unused Vue-era assets and templates.
- #56: Extend HTTP runtime tests and CI-oriented checks.
- #54: Add HTTP runtime smoke tests.
- #52: Polish Swagger UI with a consistent dark theme.
- #50: Add starter OpenAPI contract and Swagger UI.
- #16: Add PHPUnit test foundation and first pure function tests.
- #8: Rename default branch from `master` to `main`.
- #6: Add project documentation, AI guide, coding standards, workflow, roadmap, TODO, milestones, and ADR foundation.

## Next

Pick the next field trial or implementation improvement from the **Backlog Candidates** section below.

The two earlier "AI-readable reference implementation" Issues (#145, #165) were closed on 2026-05-21 — the goals they encoded are now delivered through FT3–FT6 plus the tutorial and `docs/review/` checklists. New reference-implementation needs should be spawned as new field trials.

## Field Trials

The methodology is documented in `docs/field-trials/README.md` and `docs/templates/field-trial-report.md`. Trials are cloned into `../NeNe-FT/ft{N}-{topic}/`.

When a trial is run, summarize it here with the format below, then move the block to `Recently Completed` once all follow-up Issues are merged or closed.

```
## FT{N} — {topic}

- Report: `docs/field-trials/YYYY-MM-field-trial-{N}.md`
- Baseline: {NeNe ref}
- Findings: F-1 (severity / decision / Issue #), F-2 (...), ...
```

### Recently Completed

- **FT1** — baseline trial from `ft1-bookmarklog`. Pivoted from a Bookmark+Tag implementation when baseline phase produced enough findings to fill the trial on its own. Closed 5 Issues: #222 (PdoConnection runtime fatal hotfix), #224 (CI runtime smoke job), #225 (`composer test:http` preflight), #226 (`NENE_HTTP_BASE_URL` docs), #227 (Docker `safe.directory`). Report: `docs/field-trials/2026-05-field-trial-1.md`. The originally planned Bookmark+Tag scope shifts to FT2.
- **FT2** — Bookmark + Tag M:N CRUD trial from `ft2-bookmark-tag`. Two-entity REST service with transactional relation diff, dual DB schema (SQLite + MySQL), OpenAPI extension, 6 new HTTP smoke tests. 7 findings. Follow-up Issues #237, #238, #239, #240, #241–#244 closed; F-5 escalated in FT3 and resolved via ADR-0003. Report: `docs/field-trials/2026-05-field-trial-2.md`.
- **FT3** — auth-protected Memo CRUD from `ft3-authlog`. Session + CSRF flow against REST. 6 findings; 3 follow-up Issues #251–#253 closed by PRs #254 / #255 / #256. ADR-0003 (generic OpenAPI failure envelope) born from F-1 (escalation of FT2 F-5). Report: `docs/field-trials/2026-05-field-trial-3.md`.
- **FT4** — server-rendered Note CRUD from `ft4-smarty-html`. Smarty + asset auto-discovery + HTML form POST. 9 findings; 5 follow-up Issues #263–#267 closed by PRs #268 / #269 / #270 / #272 / #273. Report: `docs/field-trials/2026-05-field-trial-4.md`.
- **FT5** — protected-notes (auth × HTML cross) from `ft5-protected-notes`. HTML login form + CSRF helper + per-controller redirect target. 10 findings; 7 follow-up Issues #276–#282 closed by PRs #283 / #284 / #285 / #286 / #287 / #289 / #290 (#287 introduced ADR-0004 `unauthorizedRedirect()` hook). Side-effect: PR #288 bumped CI health-wait timeout to 120s. Report: `docs/field-trials/2026-05-field-trial-5.md`.
- **FT6** — CLI installer tooling (`cli/initSQLite.php`, `cli/setupDatabase.php`) from `ft6-cli-tooling`. First CLI-only trial. 7 findings (5 actionable); 5 follow-up Issues #294–#298 closed by PRs #299 / #300 / #301 / #302 / #303 (including new `docs/development/cli.md` and `composer setup` shortcut). Report: `docs/field-trials/2026-05-field-trial-6.md`.

## Backlog Candidates

### Next field trial themes (FT7+)

FT1–FT6 covered REST, M:N relations, auth/CSRF, HTML rendering, auth × HTML cross, and CLI tooling. The remaining FT-untouched surfaces are:

- **error pages** — 404 / 500 templates, the catch-all in `htdocs/index.php`, error rendering for HTML vs REST contexts. Small, well-bounded.
- **production-mode deployment probe** — `NENE_APP_ENV=production` + `NENE_APP_DEBUG=0` + `NENE_SESSION_SECURE=1`, error display behavior, log file rotation. Medium surface.
- **Smarty custom plugin authoring** — `view/plugins/` (referenced by `DIR_SMARTY_PLUGINS`), how to ship a project-specific Smarty modifier or function. Niche.
- **OpenAPI authoring workflow** — now that ADR-0003 / the contract test / `docs/development/error-codes.md` / `docs/review/openapi-contract.md` are in place, a trial that adds a fresh small entity end-to-end and measures whether the documented workflow holds up.
- **schema source-of-truth consolidation (ADR candidate)** — FT6 F-2 surfaced that schema lives in three sites (`docker/mysql/init/001_schema.sql`, `cli/initSQLite.php`, `class/xion/DatabaseInstaller.php`). Long-term consolidation into a single PHP source is ADR-class. Trigger this when a future trial actually trips on the drift.

### General code-quality candidates

- Improve PHPDoc accuracy and native types across `class/xion/`, starting with shared base classes.
- Extract dispatcher route parsing and method resolution boundaries further if future tests need lower-level coverage.
- Decide PHP minimum and target version policy in an ADR if support outside Docker PHP 8.4 becomes important.
- Add CI coverage for Docker runtime checks when repository resources and runtime cost are acceptable.
- Review GitHub Actions runtime deprecation warnings and update workflow actions when Node.js 24-ready versions are available.
- Optionally deprecate `cli/initSQLite.php` toward a thin wrapper that delegates to `cli/setupDatabase.php` (would close the redundancy surfaced by FT6 F-1; held back for backwards-compat).
