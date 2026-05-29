# Releases

This file records human-readable release notes for NeNe framework tags.

## v0.3.0

Status: release tag.

`v0.3.0` is the **Xion helper-library and field-trial release**. It keeps the
renovated legacy framework shape and routing conventions from `v0.2.0`
unchanged, while consolidating ~9 months of Phase 7 field-trial work: a large
catalogue of small, single-purpose, fully-tested DB-backed helper classes
under `class/xion/`, the developer tooling that makes them cheap to produce and
review, and the architectural decisions recorded along the way.

This release bundles 356 merged pull requests since `v0.2.0` (2026-05-09). No
breaking changes to the public routing, controller, or REST conventions.

### Highlights

- **Xion helper catalogue (FT1–FT287).** ~200 new `Nene\Xion\*` helper classes
  added through the field-trial loop, spanning auth/sessions, access control &
  security, content/CMS, users/profiles, notifications, files/media, commerce &
  billing, analytics & audit, social/community, API/integration, tasks/workflows,
  and infrastructure. Every helper follows the same conventions: optional `?PDO`
  constructor injection, cross-driver SQL (MySQL + SQLite), integer-cent money,
  half-open date ranges, and an `asOf` seam for deterministic tests. See
  `class/xion/INDEX.md` for the full grouped catalogue.
- **Developer tooling.** `composer make:xion` scaffolding (with auto-INDEX
  registration), `composer xion:index` description refresh, `composer ft:done`
  three-file completion updater, `composer precommit` (format → analyze → test),
  `composer analyze:file` targeted Phan runner, the `DbUpsert` cross-driver
  upsert helper, and `CLAUDE.md` AI quick-reference.
- **Architecture decisions.** Added ADR-0003 through ADR-0013, covering the
  OpenAPI failure envelope, per-controller unauthorized-redirect hook, PHP-side
  schema single source, Symfony Mailer dependency, response decoration boundary,
  optional Bearer auth for agent/MCP routes, the schema-migration story,
  pluggable session storage, Smarty as the template engine, the PHP version
  policy, and the API versioning strategy.
- **Production-readiness boundaries.** Pluggable Redis session backend
  (`SessionHandlerFactory` / `RedisSessionHandler`, ADR-0010), observability
  (`RequestId` + `X-Request-ID`, structured JSON logs, Server-Timing), security
  headers (`ResponseDecorator`), agent Bearer auth (`BearerAuth`), operator-applied
  schema diff (`composer schema:diff`), and email sending (`Mailer` + `MailMessage`).
- **Documentation.** 96 field-trial reports under `docs/field-trials/`, the field
  trial candidates backlog, AI self-review checklists, and the operator-console
  top-page reskin.
- Updated the runtime `VERSION` constant to `0.3.0`.

### Verification

- `composer precommit` (format → analyze → test); the unit suite is at 4856 tests.
- GitHub Actions `unit` and `HTTP runtime smoke (Docker)` checks green on the
  release-preparation PR.

### Known follow-ups

- The `class/xion/` namespace has grown large and now mixes framework-core
  concerns with application-domain helpers. A post-`v0.3.0` review of the folder
  structure and the framework-core vs. helper-library boundary is planned (see
  `docs/`), and may lead to splitting the helpers into a separate package or an
  explicitly-separated namespace before `v1.0.0`.

## v0.2.0

Status: release tag.

`v0.2.0` is the small-service delivery preparation release. It keeps the renovated legacy framework shape from `v0.1.0`, then improves local development comfort, documents the next AI-assisted delivery phase, and adds clearer framework boundaries for controller security and database transactions.

### Highlights

- Changed the project license to MIT.
- Added phpMyAdmin to the Docker development environment with the `darkwolf` theme.
- Documented local Docker MySQL/phpMyAdmin credentials and development-only security expectations.
- Added Phase 6 direction for AI-readable, human-reviewable small-service delivery.
- Extracted CSRF protection decision logic from `ControllerBase` into a testable policy boundary.
- Added `Nene\Xion\TransactionManager` as the canonical transaction boundary for multi-step mapper work.
- Documented the transaction pattern in coding standards, service tutorials, and the sample page tutorial.
- Tightened selected `DataMapperBase` return types and reduced the Phan baseline.
- Changed mapper/model schema lookup to use `MODEL_CLASS` instead of mapper-name string replacement.
- Updated the runtime `VERSION` constant to `0.2.0`.

### Verification

- `composer test`
- `composer analyze`
- GitHub Actions `unit` check on release-preparation PRs.

## v0.1.0

Status: initial release tag.

`v0.1.0` is the first usable milestone for the renovated NeNe framework. It marks the point where the legacy-style structure is still intact, but the project has enough modern tooling, documentation, setup flow, and runtime checks to be cloned, installed, and evaluated on a real server.

### Verified Sample

- Public sample: `https://nene-php.com/`
- Purpose: verify the traditional Apache/PHP install path outside Docker.
- Confirmed flow: `git clone`, Composer install, root `.env` loading, database setup, public `htdocs/` document root, `/health/index`, and the sample TODO login flow.

### Highlights

- Preserved the front controller and `/{controller}/{action}` routing style.
- Preserved `Controller::actionAction()` pages, Smarty templates, and lightweight mapper/model conventions.
- Added method-specific REST handlers such as `indexGetRest()` and `indexPostRest()`.
- Added Docker local development with MySQL 8.4 and SQLite fallback support.
- Added Composer-based dependency management and current stable package updates.
- Added PHPUnit unit tests, HTTP runtime smoke tests, and OpenAPI runtime contract checks.
- Added OpenAPI 3.1 documentation and Swagger UI.
- Hardened sessions, cookie attributes, password hashing, CSRF, JSON-only REST responses, inline JSON escaping, public error display, and request variable boundaries.
- Added Phan and PHP CS Fixer foundations for gradual modernization.
- Added server install documentation and a public `/serverinstall/index` page.
- Added database setup CLI and a health check for API, database, schema, runtime environment, and database type.

### Version Meaning

This is not a promise that NeNe is a finished general-purpose framework. It is a stable checkpoint for the renovation project: small, readable, legacy-friendly, and usable enough to start service experiments without hiding the old framework shape.

Future tags should stay small and issue-driven. Prefer version bumps when a user-visible setup flow, framework convention, security boundary, or documented API contract changes.
