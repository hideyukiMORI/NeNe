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

Status: next phase.

Goal:

NeNe already has several review-cost strengths: visible URL conventions, predictable controller and REST method names, small framework code, OpenAPI, focused tests, and AI self-review checklists. Phase 6 should not turn "AI-readable" into a broad redesign goal. It should make the practical promise clearer: reduce the cost of understanding and reviewing small-service changes.

This phase should strengthen NeNe as a small-service delivery framework where a reviewer does not need to decode each contributor's personal style before checking behavior. Whether code is written by a person or assisted by an AI agent, Controller, Service, Mapper, OpenAPI, error-code, and test changes should follow the same visible pattern.

The legacy shape is a strength here. NeNe should preserve the old PHP framework habit of "look at the URL, find the controller, read the method," then add modern review aids around it: clear responsibility boundaries, self-review checklists, tests, OpenAPI, and secure defaults.

Principles:

- Prefer visible conventions over hidden framework magic.
- Optimize for reviewability: a human should quickly know where to inspect HTTP input, business rules, SQL, API contracts, and tests.
- Keep routing, controller names, REST method boundaries, configuration, and database setup easy to trace.
- Keep Controller / Service / Mapper responsibilities stable enough that different humans and AI agents produce similar shapes.
- Prefer working reference implementations over extra explanation when examples can show the expected shape.
- Keep docs, OpenAPI contracts, and tests close to the behavior they describe.
- Preserve a fast Docker-based local workflow and a clear traditional Apache/PHP server install path.
- Treat security defaults, explicit errors, CSRF, password hashing, session settings, and dependency hygiene as part of the developer experience.
- Avoid adding large abstractions that make the code harder for humans or AI agents to understand.

Future candidates:

- #163: Reframe the next phase around reducing code review cost through consistent implementation conventions.
- #164: Present NeNe publicly as a reviewable small-service PHP framework.
- #165: Use the reference implementation to prove the reviewable Controller-Service-Mapper shape.
- #145: Add a small-service reference implementation that shows the expected shape of page, REST endpoint, service/use-case, mapper, OpenAPI, and test changes.
- Make the first-service tutorial even more repeatable from clone to local verification.
- Improve comments and PHPDoc only where they help readers understand framework boundaries.
- Add lightweight checklists for small-service delivery readiness, including environment, database, OpenAPI, tests, and production safety notes.
