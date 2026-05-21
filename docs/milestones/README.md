# Milestones

Use this directory to mirror important GitHub milestones when local context is useful.

## Suggested Milestones

### legacy-framework-stabilization

Goal: finish the foundation needed to keep NeNe useful as a renovated legacy, simple, lightweight PHP framework.

Context: NeNe keeps the philosophy of an older PHP framework that was built around a small front controller, URL segment routing, controller actions, Smarty templates, and lightweight database mappers. The milestone is not a rewrite into a modern full-stack framework. It is a careful renovation that keeps the familiar construction rules while making the codebase safer and usable with current PHP tooling.

Completion criteria:

- Keep the legacy structure intact.
- Keep the URL-based routing convention intact.
- Resolve known security issues.
- Make clone-based installation simple, fast, and stable.
- Finish dispatcher behavior needed to reproduce correct REST handling.
- Complete OpenAPI introduction for public REST/API contracts.
- Keep NeNe easy, simple, and lightweight.
- Maintain onboarding and implementation support docs under `docs/`, including ADR guidance.

What was preserved:

- The front controller and `/{controller}/{action}` routing style.
- `Controller::actionAction()` for server-rendered pages.
- Smarty templates and simple asset conventions.
- Lightweight mapper/model classes instead of a heavy ORM.
- A codebase small enough to understand without learning a large framework.

What was renovated:

- Docker-based local setup with MySQL and SQLite fallback.
- PHPUnit unit tests and HTTP runtime smoke tests.
- Method-specific REST dispatch such as `indexGetRest()` and `indexPostRest()`.
- OpenAPI and Swagger UI for the sample REST contract.
- Security-sensitive paths: sessions, cookies, password hashing, CSRF, JSON-only REST responses, inline script JSON, error exposure, and request wrappers.
- Static analysis and formatting foundations with Phan and PHP CS Fixer.

Real server sample:

- `https://nene-php.com/` is the current public sample deployment for NeNe.
- The deployment validates the non-Docker path: `git clone`, Composer install, root `.env` loading, database initialization, public `htdocs/` document root, health check, and the React TODO sample.
- The sample intentionally remains small. Its purpose is to prove that the renovated legacy framework can be installed on a traditional Apache/PHP server without turning the project into a large full-stack framework.

Current status: known stabilization Issues are complete. No open Issues remain for this milestone. The current milestone state is suitable for the first framework tag, `v0.1.0`.

### docs-foundation

Purpose: establish project documentation, AI guidance, workflow, roadmap, TODO, and ADR practice.

Current status: core project docs, workflow docs, API docs, TODO tracking, renovation philosophy, and the service implementation tutorial are in place. No open Issues remain for this milestone.

### php-modernization

Purpose: keep PHP and Composer dependencies current, improve PHPDoc and typing, and make formatting/static analysis repeatable.

Current status: modernization foundation is in place. Composer packages were updated where practical, unused packages/assets were removed, Docker PHP 8.4 is the documented target, PHP CS Fixer is configured, and Phan has a repeatable baseline. Remaining work should be gradual PHPDoc/type/baseline cleanup in focused Issues, not a broad rewrite of stable legacy classes.

### security-hardening

Purpose: reduce security risk through dependency updates, secure defaults, input/output review, and safer error handling.

Current status: focused security hardening is complete for the reviewed request/session/template/error-handling paths. Sessions, cookie attributes, password hashing, CSRF, JSON-only REST responses, inline script JSON encoding, Dispatcher JSON errors, authentication status codes, and request variable storage have been hardened. Future security work should be handled as new small Issues when findings appear.

### openapi-contracts

Purpose: document public REST endpoints with OpenAPI and keep API behavior explicit.

Current status: the starter OpenAPI contract and Swagger UI are in place for the current Session and TODO sample endpoints. Runtime contract tests verify that documented operations and observed statuses stay aligned. Future work should expand the contract when new REST endpoints are added, rather than inventing endpoints only to grow OpenAPI.

### framework-architecture-policy

Purpose: protect NeNe's renovated legacy architecture from unnecessary redesign.

Current status: no broad architecture rewrite is planned. NeNe should keep the front controller, `/{controller}/{action}` routing, `{action}Action()` page handlers, method-specific REST handlers, Smarty templates, and lightweight mapper/model style. Architecture work should document boundaries, add tests, or clarify conventions. Any change that hides URL routing, replaces the MVC shape, adds a heavy ORM, or changes compatibility policy should require a focused Issue and ADR.

### release-management

Purpose: make framework progress visible through small versioned tags.

Current status: `v0.1.0` is the first planned tag. It represents the point where NeNe has a working local Docker setup, traditional server install documentation, MySQL/SQLite sample database setup, a public `nene-php.com` sample deployment, OpenAPI/Swagger UI, tests, and a clear renovated-legacy framework policy.

### reviewable-small-service-delivery

Status: substantially complete as of 2026-05-21.

Goal: make NeNe a small PHP framework that lowers code review cost by keeping human-written and AI-assisted small-service changes in the same visible implementation shape.

Context: NeNe's next phase should build on the renovated legacy foundation. The framework should not grow into a large full-stack platform, and it does not need a broad redesign just to become "AI-readable." It already has visible `/{controller}/{action}` flow, predictable controller and REST method names, a small codebase, Docker setup, OpenAPI, tests, security defaults, and self-review checklists. The next step is to make those strengths reduce real review friction: a reviewer should quickly know where to inspect HTTP input, business rules, SQL, API contracts, and tests.

The legacy inheritance matters. Older PHP frameworks often made control flow easy to trace because the route, controller, action, and mapper were visible. This milestone keeps that readability, then adds modern review aids so different humans and different AI tools still produce code that looks like normal NeNe code.

Modern patterns remain useful, but this milestone should avoid making every review depend on understanding a different author's preferred architecture. For small services, a simple visible convention is often more valuable than a cooler abstraction if it lets reviewers focus on behavior confidently.

Positioning:

- Review-cost reduction as the practical value of explicit conventions.
- AI-readable as an outcome of stable human-reviewable patterns, not as a separate architecture goal.
- Human-friendly and review-friendly, not magic-heavy.
- Modern safety rails without requiring reviewers to decode a different architecture style for ordinary changes.
- Fast for small services, not a replacement for large enterprise frameworks.
- Secure by default for realistic local development and small deployments.

Scope:

- Keep conventions explicit enough that AI-assisted and human-written changes follow the same shape.
- Keep Controller / Service / Mapper responsibilities stable enough that implementation style does not vary by author or AI tool.
- Use working reference implementations to show the preferred shape for small features.
- Improve tutorials, examples, comments, and PHPDoc where they reduce review friction.
- Keep the path from clone to local verification short and repeatable.
- Keep traditional Apache/PHP deployment guidance clear for small real-world projects.
- Maintain focused tests, OpenAPI contracts, and security defaults as part of the delivery workflow.

Completion criteria:

- Documentation clearly describes NeNe as review-friendly and AI-readable as a result of stable conventions, without overstating AI guarantees or implying a broad architecture rewrite.
- A first service can be built by following docs from page/controller through Service/use-case, REST endpoint, database access, OpenAPI, and tests.
- #163 defines the review-cost-reduction framing for Phase 6.
- #164 updates the public entry message around reviewable small-service delivery.
- ~~#165 and #145 define a concrete reference implementation~~ — both closed 2026-05-21; the FT3–FT6 trial-driven PRs, the tutorial extensions added during those trials, and the `docs/review/` self-review checklists (PR #291) deliver the same value through smaller reviewable changes.
- #167 explains how stable conventions reduce review load caused by highly variable implementation styles.
- Local Docker setup remains simple, including app, MySQL, phpMyAdmin, Swagger UI, and test commands.
- Production-facing docs continue to warn about secrets, debug output, local Docker credentials, phpMyAdmin exposure, and database initialization.
- New examples do not introduce hidden routing, heavy ORM behavior, or broad framework abstractions.

Additional artifacts that consolidate this milestone:

- ADR 0003 (canonical OpenAPI failure envelope shape).
- ADR 0004 (`ControllerBase::unauthorizedRedirect()` hook for per-controller redirect targets).
- `docs/review/` self-review checklists: REST controller, HTML controller, database, OpenAPI contract, docs/ADR, release/CI, field-trial report (PR #291, adapted from NENE2's pattern).
- `docs/tutorials/building-a-service.md` now covers HTML form POST handling, Protect an Authenticated Form, HTML login form, asset auto-discovery convention, and URL controller naming, in addition to the original REST flow.
- `docs/development/cli.md` (new) declares `cli/setupDatabase.php` as the canonical installer and `cli/initSQLite.php` as the legacy alternative.

### field-trials-loop

Status: continuous, running since 2026-05-20.

Goal: keep external usability evidence-driven by running fresh-clone field trials (`tools/nene-ft-new.sh {topic}`) on different surfaces and closing every spawned Issue before the next trial starts.

Context: methodology is documented in ADR 0002 and `docs/field-trials/README.md`. The loop is described as a continuous quality gate rather than a one-off project, so it lives as a rolling milestone instead of a closeable goal.

Completion criteria:

- The loop is **never** marked complete; it is alive as long as NeNe is maintained.
- Each trial produces a report under `docs/field-trials/YYYY-MM-field-trial-{N}.md` from `docs/templates/field-trial-report.md`.
- Each non-deferred finding is filed as a focused GitHub Issue with the `field-trial` label and closed by a merged PR before the next trial starts.
- Deferred findings live in `docs/field-trials/follow-ups.md` with an explicit re-evaluation trigger.

Completed trials (2026-05-20 → 2026-05-21): FT1, FT2, FT3, FT4, FT5, FT6.

Linked Issues: every Issue carrying the `field-trial` label.

## Maintenance Rule

When a GitHub milestone becomes active, update this directory with:

- Goal.
- Scope.
- Linked Issues.
- Completion criteria.
