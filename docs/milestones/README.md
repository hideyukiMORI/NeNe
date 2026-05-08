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
- Security-sensitive paths: sessions, cookies, password hashing, CSRF, JSONP, inline script JSON, error exposure, and request wrappers.
- Static analysis and formatting foundations with Phan and PHP CS Fixer.

Current status: known stabilization Issues are complete. No open Issues remain for this milestone.

### docs-foundation

Purpose: establish project documentation, AI guidance, workflow, roadmap, TODO, and ADR practice.

Current status: core project docs, workflow docs, API docs, TODO tracking, renovation philosophy, and the service implementation tutorial are in place. No open Issues remain for this milestone.

### php-modernization

Purpose: keep PHP and Composer dependencies current, improve PHPDoc and typing, and make formatting/static analysis repeatable.

### security-hardening

Purpose: reduce security risk through dependency updates, secure defaults, input/output review, and safer error handling.

### openapi-contracts

Purpose: document public REST endpoints with OpenAPI and keep API behavior explicit.

## Maintenance Rule

When a GitHub milestone becomes active, update this directory with:

- Goal.
- Scope.
- Linked Issues.
- Completion criteria.
