# Milestones

Use this directory to mirror important GitHub milestones when local context is useful.

## Suggested Milestones

### legacy-framework-stabilization

Goal: finish the foundation needed to keep NeNe useful as a legacy, simple, lightweight PHP framework.

Completion criteria:

- Keep the legacy structure intact.
- Keep the URL-based routing convention intact.
- Resolve known security issues.
- Make clone-based installation simple, fast, and stable.
- Finish dispatcher behavior needed to reproduce correct REST handling.
- Complete OpenAPI introduction for public REST/API contracts.
- Keep NeNe easy, simple, and lightweight.
- Maintain onboarding and implementation support docs under `docs/`, including ADR guidance.

Current status: known stabilization Issues are complete, including local Docker setup, test foundation, REST dispatch behavior, OpenAPI/Swagger UI, SQLite fallback, and focused security hardening. No open Issues remain for this milestone.

### docs-foundation

Purpose: establish project documentation, AI guidance, workflow, roadmap, TODO, and ADR practice.

Current status: core project docs, workflow docs, API docs, TODO tracking, and the service implementation tutorial are in place. No open Issues remain for this milestone.

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
