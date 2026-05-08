# Releases

This file records human-readable release notes for NeNe framework tags.

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
