# Architecture Decision Records

Use ADRs to record decisions that shape NeNe's architecture and long-term maintenance.

## When to Add an ADR

Create an ADR when a change affects:

- Routing and controller conventions.
- PHP version support.
- Dependency policy.
- Security posture.
- OpenAPI or public API contracts.
- Template, logging, database, or configuration architecture.
- Compatibility with legacy applications.

## Status Values

- `Proposed`
- `Accepted`
- `Deprecated`
- `Superseded`

## Index

- [`0001-record-architecture-decisions.md`](0001-record-architecture-decisions.md) (Accepted): Start recording architecture decisions.
- [`0002-adopt-field-trial-methodology.md`](0002-adopt-field-trial-methodology.md) (Accepted): Adopt the field trial methodology as a continuous quality practice.
- [`0003-openapi-failure-envelope-shape.md`](0003-openapi-failure-envelope-shape.md) (Accepted, FT3 #256): Use a single generic `ApiFailureEnvelope` schema for every REST failure status.
- [`0004-controllerbase-unauthorized-redirect-hook.md`](0004-controllerbase-unauthorized-redirect-hook.md) (Accepted, FT5 #287): Add `unauthorizedRedirect()` hook so HTML controllers can override the global `LOGOUT_URI` per-section.
- [`0005-schema-php-single-source.md`](0005-schema-php-single-source.md) (Accepted, FT11 #358): Make `class/xion/SchemaDefinition.php` the canonical schema source; `SchemaCompiler` generates MySQL + SQLite DDL.
- [`0006-symfony-mailer-as-mail-dep.md`](0006-symfony-mailer-as-mail-dep.md) (Accepted, FT13 #379): Adopt `symfony/mailer` for the framework's mail surface; `Mailer` + `MailMessage` helpers; `null://null` default keeps CI offline.
- [`0007-response-decoration-boundary.md`](0007-response-decoration-boundary.md) (Accepted, FT14 #387): `Nene\Xion\ResponseDecorator` at the `HttpEmitter::emit()` (and `View::execute()`) boundary hosts every cross-cutting response concern; closes the FT7 F-6 / FT8 F-4 decoration trap.
- [`0008-optional-bearer-for-agent-routes.md`](0008-optional-bearer-for-agent-routes.md) (Accepted, FT16 #399): Optional `Authorization: Bearer` for stateless agent / MCP clients (`Nene\Xion\BearerAuth`); browser cookie+CSRF flow unchanged.
- [`0009-schema-migration-story.md`](0009-schema-migration-story.md) (Accepted, #409 — commercial-feasibility report follow-up): Adopt an operator-applied `cli/schemaDiff.php` that reads `SchemaDefinition` and emits `ALTER TABLE` statements for the operator to review and apply. Destructive ops stay hand-written.
- [`0010-session-storage-backend.md`](0010-session-storage-backend.md) (Accepted, FT18 #429): Pluggable session storage via `SessionHandlerFactory` + `RedisSessionHandler`; `NENE_SESSION_DSN` env; Redis default in Docker Compose.
- [`0011-smarty-as-template-engine.md`](0011-smarty-as-template-engine.md) (Accepted retrospective, #437): Smarty v5 is the template engine. Records why Smarty was chosen over Twig / Blade / plain PHP and under what conditions to reconsider.
- [`0012-php-version-policy.md`](0012-php-version-policy.md) (Accepted, #441): PHP >=8.4 declared in composer.json; upgrade cadence policy for minor bumps, future 8.5+ and 9.0 decisions.
- [`0013-api-versioning.md`](0013-api-versioning.md) (Accepted, FT39): URI prefix versioning (`/v1/`, `/v2/`) with RFC 8594 `Deprecation`/`Sunset`/`Link` headers via `ApiDeprecation::sendHeaders()`.
