# Xion Class Index

Quick reference for all classes in `class/xion/`. Grouped by functional domain.

---

## Auth & Sessions

| Class | Description |
|-------|-------------|
| `AuthSession` | — |
| `BearerAuth` | Optional Bearer-token authentication for agent / MCP clients (ADR-0008). |
| `JwtCodec` | — |
| `RedisSessionHandler` | — |

---

## Access Control & Security

| Class | Description |
|-------|-------------|
| `CsrfProtectionPolicy` | — |
| `RedirectGuard` | Open-redirect guard for controllers that need to redirect to an |
| `RoleGuard` | — |

---

## Content & CMS

| Class | Description |
|-------|-------------|
| `Post` | — |

---

## Users & Profiles

| Class | Description |
|-------|-------------|

---

## Notifications & Messaging

| Class | Description |
|-------|-------------|
| `MailMessage` | Plain immutable description of one outgoing email. |
| `Mailer` | Thin wrapper around Symfony Mailer (ADR-0006). |

---

## Files & Media

| Class | Description |
|-------|-------------|
| `FileUpload` | Safe wrapper for a single uploaded file ($_FILES entry). |
| `UploadedFile` | Typed wrapper around a single `$_FILES` entry. |

---

## Commerce & Billing

| Class | Description |
|-------|-------------|

---

## Analytics & Audit

| Class | Description |
|-------|-------------|
| `AuditLogger` | Append-only audit log writer. |
| `Log` | — |
| `LogFormatterFactory` | — |

---

## Social & Community

| Class | Description |
|-------|-------------|

---

## API & Integration

| Class | Description |
|-------|-------------|
| `ApiResponse` | — |
| `BearerAuth` | Optional Bearer-token authentication for agent / MCP clients (ADR-0008). |
| `HttpCache` | HTTP cache header utilities for REST endpoints. |
| `RequestId` | Per-request identifier used for end-to-end correlation across |
| `ServerTiming` | — |

---

## Tasks & Workflows

| Class | Description |
|-------|-------------|
| `TransactionManager` | — |

---

## Infrastructure & DB

| Class | Description |
|-------|-------------|
| `DatabaseInstaller` | Database setup and health checks for the sample runtime. |
| `Command` | — |
| `DataMapperBase` | — |
| `DataModelBase` | — |
| `DbUpsert` | Cross-driver upsert helper (MySQL + SQLite). |
| `DomainException` | Throwable that carries a NeNe error code to the top-level request handler. |
| `EnvLoader` | Minimal dotenv-style loader for CLI setup commands. |
| `ErrorCode` | — |
| `GenerateSchemaSqlCommand` | — |
| `InitSqliteCommand` | — |
| `Initialize` | — |
| `PdoConnection` | — |
| `SchemaCompiler` | Compile {@see SchemaDefinition} into MySQL and SQLite DDL. |
| `SchemaDefinition` | NeNe's sample-schema source of truth. |
| `SchemaDiffCommand` | — |
| `SchemaDiffer` | Schema-diff engine — compares a live database introspection against |
| `SessionHandlerFactory` | — |
| `SetupDatabaseCommand` | — |

---

## HTTP Layer

| Class | Description |
|-------|-------------|
| `ControllerBase` | — |
| `Cursor` | — |
| `CursorPage` | — |
| `Dispatcher` | — |
| `HttpEmitter` | — |
| `HttpResponse` | — |
| `HttpTermination` | — |
| `JsonResponder` | — |
| `ModelBase` | — |
| `OffsetPage` | Paginated result set with offset-based (page number) navigation metadata. |
| `QueryString` | — |
| `RedirectGuard` | Open-redirect guard for controllers that need to redirect to an |
| `Request` | — |
| `RequestVariables` | — |
| `ResponseDecorator` | Cross-cutting response decoration that every PHP-generated response |
| `RouteContext` | — |
| `UrlParameter` | — |
| `View` | — |

---

*Generated from PHPDoc descriptions. Run `composer xion:index` to refresh after adding classes.*

