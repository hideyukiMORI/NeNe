# Roadmap

This roadmap describes NeNe's current direction and status. GitHub Issues and PRs remain the source of truth for actionable work.

NeNe is a renovation project for an old, small PHP framework style. The goal is not a broad redesign. The goal is to keep the familiar legacy construction rules while making the project usable with current PHP, security, documentation, and testing practices.

## Guiding Policy

NeNe should continue to emphasize:

- URL-segment routing: `/{controller}/{action}`.
- Controller action naming: `{action}Action()` for server-rendered pages.
- Method-specific REST naming: `{action}{HttpMethod}Rest()` for JSON APIs.
- Smarty-based server rendering as the default HTML path.
- Lightweight mapper/model classes instead of a heavy ORM.
- Small, readable framework code over large abstractions.
- Explicit conventions that reduce code review cost by making generated or hand-written changes follow the same shape.
- A short path from `git clone` to local verification and small-service delivery.
- Modern safety rails around the legacy shape: Docker, tests, OpenAPI, Phan, PHP CS Fixer, CSRF, password hashing, error catalogs, and safer output handling.

NeNe should avoid:

- Replacing the core with a large full-stack framework.
- Introducing configurable routing that hides the URL convention.
- Rewriting the MVC shape only to look more modern.
- Adding an ORM, plugin system, or SPA-first architecture before a real service need exists.

## 0. Legacy Framework Stabilization

Status: substantially complete.

Completed:

- Preserved the legacy directory structure and URL-based routing rules.
- Added Docker-based local development with MySQL.
- Kept SQLite fallback aligned with the sample TODO tables.
- Finished method-specific REST dispatch behavior.
- Added OpenAPI and Swagger UI for current public REST endpoints.
- Added PHPUnit unit tests and HTTP runtime smoke tests.
- Resolved the known focused security hardening Issues.
- Added onboarding docs and a service implementation tutorial.

Ongoing policy:

- Keep the old-school lightweight framework character.
- Treat future stabilization work as small Issues, not a new architecture phase.

## 1. Documentation Foundation

Status: substantially complete.

Completed:

- Added AI and contributor guidance.
- Added workflow, roadmap, TODO, milestone, ADR, coding standards, and commit convention docs.
- Documented the renovation philosophy and target audience.
- Documented current routing and controller conventions.
- Added API docs and service-building tutorial guidance.

Ongoing policy:

- Update docs when conventions change.
- Keep docs focused on how NeNe is actually used, not generic framework theory.

## 2. PHP Modernization

Status: foundation complete; gradual cleanup remains ongoing.

Completed:

- Updated direct Composer packages to current stable versions where practical.
- Removed unused packages and generated/legacy assets where appropriate.
- Set Docker PHP 8.4 as the development target.
- Added PHP CS Fixer configuration and repeatable format scripts.
- Added Phan configuration and baseline for repeatable static analysis.
- Improved typing and PHPDoc in touched areas.

Ongoing policy:

- Improve PHPDoc, native types, and baseline reductions only in focused Issues.
- Do not rewrite stable legacy classes just to remove historical style.

## 3. Security Hardening

Status: focused hardening complete; security remains a maintenance practice.

Completed:

- Controlled public error display through environment settings.
- Hardened session lifecycle and cookie attributes.
- Switched sample credentials to password hashes.
- Added CSRF protection for state-changing cookie-authenticated REST requests.
- Removed legacy JSONP output and standardized REST responses on JSON.
- Safely encoded inline script JSON.
- Moved Dispatcher errors into the shared JSON error responder.
- Changed authentication failures to HTTP `401`.
- Fixed request variable storage and added boundary tests.

Ongoing policy:

- Treat new security findings as small, prioritized Issues.
- Keep error messages, HTTP status values, and response shape centralized.
- Do not expose internal paths, stack traces, SQL details, or secrets in production responses.

## 4. OpenAPI and API Contracts

Status: starter contract complete; expansion should follow real endpoints.

Completed:

- Defined `docs/api/openapi.yaml` as the source OpenAPI contract.
- Added Swagger UI served from the committed contract.
- Added runtime checks that documented operations and observed statuses stay aligned.
- Migrated YAML parsing to `symfony/yaml` for structured contract reading.
- Documented REST method conventions and auth/CSRF behavior.

Ongoing policy:

- Add or update OpenAPI when adding public REST endpoints.
- Avoid building a custom full OpenAPI validator; choose a library deliberately if body schema validation becomes necessary.

## 5. Framework Architecture

Status: policy clarified; no broad redesign planned.

Current decision:

- Keep the current front controller, URL routing, controller action, Smarty, and lightweight mapper architecture.
- Use ADRs only when a change would alter routing, controller conventions, dependency policy, compatibility, security posture, or API boundaries.
- Prefer documenting boundaries and adding tests over replacing the core design.

Future candidates:

- Extract dispatcher route parsing further only if future tests or services need it.
- Reduce legacy static-analysis baseline issues in focused cleanup PRs.
- Revisit CI Docker runtime coverage when repository resources and runtime cost make it practical.

## 6. Reviewable Small-Service Delivery

Status: substantially complete as of 2026-05-21. The phase's principles are now reinforced by the field-trial loop (Phase 7) plus four trial-driven artifacts:

- ADR 0003 (canonical OpenAPI failure envelope shape) keeps API contracts uniform across endpoints.
- ADR 0004 (`ControllerBase::unauthorizedRedirect()` hook) keeps auth-redirect behavior overridable per controller without breaking the dispatch invariant.
- `docs/review/` self-review checklists (REST controller, HTML controller, database, OpenAPI contract, docs/ADR, release/CI, field-trial report) document the standard shape humans and AI agents should produce.
- The tutorial `docs/tutorials/building-a-service.md` now covers HTML form POST, Protect an Authenticated Form, HTML login form, asset auto-discovery, URL controller naming, OpenAPI integration — the previously missing reference flows.

The two "reference implementation" Issues that originally framed this phase (#145, #165) were closed on 2026-05-21 because the trial-driven work delivers the same value through smaller, reviewable PRs.

Goal:

NeNe already has several review-cost strengths: visible URL conventions, predictable controller and REST method names, small framework code, OpenAPI, focused tests, and AI self-review checklists. Phase 6 should not turn "AI-readable" into a broad redesign goal. It should make the practical promise clearer: reduce the cost of understanding and reviewing small-service changes.

This phase should strengthen NeNe as a small-service delivery framework where a reviewer does not need to decode each contributor's personal style before checking behavior. Whether code is written by a person or assisted by an AI agent, Controller, Service, Mapper, OpenAPI, error-code, and test changes should follow the same visible pattern.

The legacy shape is a strength here. NeNe should preserve the old PHP framework habit of "look at the URL, find the controller, read the method," then add modern review aids around it: clear responsibility boundaries, self-review checklists, tests, OpenAPI, and secure defaults.

Modern design patterns and current coding styles are useful, but very open implementation style can make each review start with a different architecture lesson. NeNe should avoid making small-service delivery depend on decoding every contributor's preferred pattern when a visible convention is enough.

Principles:

- Prefer visible conventions over hidden framework magic.
- Prefer broadly reviewable conventions over fashionable patterns when both solve the same small-service problem.
- Optimize for reviewability: a human should quickly know where to inspect HTTP input, business rules, SQL, API contracts, and tests.
- Keep routing, controller names, REST method boundaries, configuration, and database setup easy to trace.
- Keep Controller / Service / Mapper responsibilities stable enough that different humans and AI agents produce similar shapes.
- Prefer working reference implementations over extra explanation when examples can show the expected shape.
- Keep docs, OpenAPI contracts, and tests close to the behavior they describe.
- Preserve a fast Docker-based local workflow and a clear traditional Apache/PHP server install path.
- Treat security defaults, explicit errors, CSRF, password hashing, session settings, and dependency hygiene as part of the developer experience.
- Avoid adding large abstractions that make the code harder for humans or AI agents to understand.

Completed:

- #163: Reframed the next phase around reducing code review cost through consistent implementation conventions.
- #164: Updated the public entry to present NeNe as a reviewable small-service PHP framework.
- #167: Added the review-cost angle around modern pattern learning and implementation-style variance.
- #145, #165 (closed 2026-05-21): the AI-assisted reference implementation and the reviewable Controller-Service-Mapper proof are delivered through the FT3–FT6 trial-driven PRs, tutorial extensions, and `docs/review/` checklists rather than a single dedicated reference-implementation Issue.
- ADR 0003 — canonical OpenAPI failure envelope shape.
- ADR 0004 — `ControllerBase::unauthorizedRedirect()` hook.
- #271: imported NENE2's `docs/review/` self-review checklist shape, adapted to NeNe's surfaces.

Future candidates:

- Improve comments and PHPDoc only where they help readers understand framework boundaries.
- Promote the project (#178 / #179 / #180) once the Phase 6 conventions are stable enough to demo externally.

## 7. Field Trials

Status: methodology adopted (ADR 0002). FT1–FT210 complete as of 2026-05-27 (FT36 deferred as ADR-class; FT112 closed as conflicting with FT61).

Goal:

Verify NeNe from the outside by cloning it into `../NeNe-FT/ft{N}-{topic}/` and building a small realistic service while recording every point of friction as a numbered finding (`F-1`, `F-2`, ...). Trials drive small framework and documentation Issues. Findings that turn out to be intentional legacy shapes are recorded as `legacy-preserved` with a documentation-only decision, rather than as defects.

Field trials are a continuous quality gate rather than a single project. A new trial should run whenever a meaningful release, modernization step, or documentation rewrite lands and external usability should be re-checked.

Principles:

- One trial, one short report. Quantity does not matter; readable evidence does.
- Use a fresh clone every time. The trial directory is independent and never committed back into this repository.
- Record what surprised, blocked, or slowed down the work. Do not pad reports with speculative improvements.
- Distinguish documentation gaps from intentional legacy shapes. NeNe is a renovation, not a redesign.
- Each finding ends in a Decision: `fix-in-framework`, `document`, `keep-legacy`, or `defer`.

Methodology and templates:

- `docs/field-trials/README.md`: full methodology, clone layout, friction kinds, decisions, and safety rules.
- `docs/templates/field-trial-report.md`: report skeleton copied per trial.

Completed:

- **FT1** — baseline trial from `ft1-bookmarklog`. The intended scope was Bookmark+Tag CRUD, but the baseline phase produced enough findings to fill the trial. Outcomes: a 1-line `main` hotfix (PR #223 closing a PHP fatal in `PdoConnection::__destruct()`), a new CI HTTP runtime smoke job (#230), and three small UX / docs improvements (#228, #229, #231). Report: `docs/field-trials/2026-05-field-trial-1.md`.
- **FT2** — Bookmark + Tag M:N CRUD from `ft2-bookmark-tag`. 7 findings; 4 filed as Issues (TransactionManager domain-error path, URL parameter convention docs, junction-table guidance, schema parity note). FT2 F-5 was later escalated in FT3 and resolved via ADR-0003. Report: `docs/field-trials/2026-05-field-trial-2.md`.
- **FT3** — auth + CSRF REST trial from `ft3-authlog`. 6 findings; ADR-0003 (canonical OpenAPI failure envelope shape) born from F-1 (escalation of FT2 F-5). Report: `docs/field-trials/2026-05-field-trial-3.md`.
- **FT4** — server-rendered HTML trial from `ft4-smarty-html`. 9 findings covering Smarty escape behavior, asset auto-discovery convention, HTML form POST handling, compile cache hygiene, and a small `location()` URI fix. Report: `docs/field-trials/2026-05-field-trial-4.md`.
- **FT5** — auth × HTML cross trial from `ft5-protected-notes`. 10 findings; ADR-0004 (`ControllerBase::unauthorizedRedirect()` hook) born. The CI workflow's `Wait for /health` step was hardened to require `healthStatus=ok` with a 120s budget after a debugging mis-diagnosis. Report: `docs/field-trials/2026-05-field-trial-5.md`.
- **FT6** — CLI installer trial from `ft6-cli-tooling` (first CLI-only trial). 7 findings; `composer setup` shortcut, `cli/setupDatabase.php` `--env=PATH` strict mode, `cli/initSQLite.php` `--yes` / `--help`, schema 3-way parity documentation, new `docs/development/cli.md` declaring `setupDatabase.php` canonical and `initSQLite.php` legacy. Report: `docs/field-trials/2026-05-field-trial-6.md`.
- **FT7–FT24** — infrastructure, tooling, and survey trials. ADR-0005–0013 established. AI-agent journey (FT22), NENE2 pattern survey (FT23), CLI framework (FT24).
- **FT25–FT50** — NENE2 parity + extended pattern wave (26 trials). PRs #458–#483. Pagination, soft delete, JWT, RBAC, rate limiting, feature flags, idempotency, webhook signing, circuit breaker, and more.
- **FT51–FT77** — extended social/content/identity wave (27 trials). PRs #485–#511. i18n, pub-sub, GDPR export, leaderboard, comment threads, TOTP, address book, and more.
- **FT78–FT140** — Xion helper wave (63 trials). PRs #512–#574. Social, content moderation, SaaS infrastructure, analytics, A/B testing, event sourcing, consent management, and more. See `docs/todo/current.md` for the full list.

Infrastructure landed alongside the trials:

- `tools/nene-ft-new.sh` one-shot clone bootstrap (port offset, `.claude/settings.local.json`, `.claude/CLAUDE.md`, `FT{N}-PLAN.md` skeleton, sanity check that blocks accidental clone-cwd invocation).
- `field-trial` GitHub label applied retroactively to historical trial-related Issues.
- `main` branch protection with required status checks; the FT3–FT6 follow-up loops merged via `gh pr merge --auto`.
- `docs/review/field-trial-report.md` self-review checklist referenced by the methodology.
- `docs/field-trials/follow-ups.md` rules for deferred findings, with FT2 F-5 escalated and removed during FT3.

Completed (wave summary):

- **FT7–FT24**: Infrastructure, tooling, and pattern survey trials. ADR-0005–0013. NENE2 pattern survey (FT23). AI-agent journey (FT22).
- **FT25–FT50**: NENE2 parity + extended pattern wave. 26 trials covering pagination, soft delete, JWT, RBAC, rate limiting, feature flags, idempotency, webhook signing, and more. PRs #458–#483.
- **FT51–FT77**: Extended social/content/identity wave. 27 trials covering i18n, pub-sub, GDPR export, leaderboard, bookmarks, TOTP, address book, and more. PRs #485–#511.
- **FT78–FT140**: Xion helper wave. 63 trials covering social, content moderation, analytics, SaaS infrastructure, A/B testing, event sourcing, and more. PRs #512–#574.
- **FT141–FT165**: Xion extended wave. 25 trials covering config store, cache, user groups, chat, shopping cart, cron log, export jobs, IP blocklist, content versioning, alert rules, announcements, magic links, slug registry, email queue, document locks, API usage log, OAuth tokens, user activity, email templates, batch jobs, service accounts, change logs, push subscriptions, counter metrics, and file chunk uploads. PRs #576–#600.
- **FT166–FT175**: Xion second extended wave. 10 trials covering plan-based quota management, shareable links with password/expiry, approval workflows, team membership with roles, fraud/trust scoring, payment records, PIN/OTP codes, anonymous guest sessions, cron-style task scheduling, and admin user notes. PRs #602–#611.
- **FT176–FT185**: Xion third extended wave. 10 trials covering colored labels, ordered checklists, emoji reactions, @-mention tracking, watchlists, file attachments, saved searches, content filtering, user reminders, and knowledge base articles. PRs #613–#622.
- **FT186–FT195**: Xion fourth extended wave. 10 trials covering support ticketing, event sourcing log, daily digest accumulator, per-resource ACL, 2FA backup codes, typed global settings, ordered media gallery, entity reviews with ratings, FAQ articles, and gamification tier tracking. PRs #624–#633.
- **FT196–FT200**: Xion fifth extended wave. 5 trials covering CSV import job tracking with per-row errors, topic-based pub/sub subscriptions, e-commerce orders with line items, deployment version history, and user cohort/segment assignment. PRs #635–#639.
- **FT201–FT210**: Xion sixth extended wave. 10 trials covering inventory stock tracking with atomic reserve/commit, work time entries with start/stop timers, appointment time slot booking with capacity, external API call logging, GDPR data-subject request tracking, product/feature waitlist management, regional tax rate calculation, push notification device token management, flexible entity tagging with tag clouds, and advisory resource locking with TTL. PRs #641–#650.

Future candidates:

- **Trigger-based**: error pages (404/500 templates), production-mode deployment probe (`NENE_APP_ENV=production`), OpenAPI authoring workflow, FT36 (background jobs / ADR-class).
- Subsequent trials should target surfaces not yet exercised, or validate revised Xion helper designs in a real service context.
