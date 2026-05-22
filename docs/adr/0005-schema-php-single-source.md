# ADR 0005: PHP-Side Schema Single Source

## Status

Accepted

## Context

NeNe's bundled sample app (`users`, `todos`) historically had its schema written in **three independent places**:

1. `docker/mysql/init/001_schema.sql` — the file MySQL's docker-entrypoint runs on a fresh data volume.
2. `class/xion/DatabaseInstaller.php::createMySQLTables()` — a heredoc-string `CREATE TABLE` block called by `cli/setupDatabase.php` when the operator wants the framework to install MySQL tables outside the docker-entrypoint path.
3. `class/xion/DatabaseInstaller.php::createSQLiteTables()` — a parallel heredoc for the SQLite fallback runtime.

PR #295 (Field Trial 6 follow-up) documented the duplication but accepted it as deferred friction. The re-evaluation trigger was "when a future trial actually trips on the drift, or a new bundled sample adopts a unique column that needs the same workaround." Field Trial 10 (`docs/field-trials/2026-05-field-trial-10.md`, finding F-3) tripped on it: round-tripping a `Memo` entity through the documented OpenAPI authoring workflow required touching all three sites for one schema change.

The three sites are not interchangeable:

- Site (1) is consumed by an external process (MySQL container init) — it has to ship as a `.sql` file.
- Site (2) and (3) are runtime PHP — they have to execute through PDO.

So the question is not "delete two of them" but "which artifact is the source of truth, and how do the other two stay in sync."

## Considered options

| Option | Source of truth                                                | Generated artifacts                                                  | External tooling     |
| ------ | -------------------------------------------------------------- | -------------------------------------------------------------------- | -------------------- |
| **A**  | PHP description (`class/xion/SchemaDefinition.php`)            | MySQL DDL (in-process) + SQLite DDL (in-process) + `001_schema.sql`  | None (composer only) |
| B      | `001_schema.sql` (canonical)                                   | SQLite DDL via transpile; PHP runtime via parse                      | sqlite-tools or similar SQL→SQL transpiler |
| C      | `001_schema.sql` (canonical) + hand-written `DatabaseInstaller`| `DatabaseInstaller` blocks regenerated at build time                 | Some kind of SQL→PHP code generator |

Option B introduces an external SQL transpiler dependency that NeNe's "small framework that runs on `php` + `composer` alone" character explicitly avoids. Option C still requires a generator and inverts the natural direction (a PHP framework whose source of truth is a `.sql` file is awkward to extend with new sample entities at the PHP layer).

Option A fits NeNe's shape: the framework already describes tables in PHP (`DataModelBase::$schema` arrays). Extending that pattern to a single `SchemaDefinition::tables()` array plus a small `SchemaCompiler` that emits each dialect is direct PHP, composes with the rest of `class/xion/`, and ships with the framework — no new build step, no new toolchain.

## Decision

- Add `class/xion/SchemaDefinition.php` describing every bundled sample table as a PHP array using a small, explicit type vocabulary (`pk-bigint`, `bigint`, `varchar:NN`, `text`, `bool`, `datetime-now`, `datetime-touch`).
- Add `class/xion/SchemaCompiler.php` exposing four pure static methods:
  - `mysqlStatements(): array<int,string>` — one `CREATE TABLE` per table, MySQL dialect.
  - `sqliteStatements(): array<int,string>` — same, SQLite dialect.
  - `sqliteIndexStatements(): array<int,string>` — `CREATE INDEX` statements (SQLite requires them outside `CREATE TABLE`).
  - `sqliteTriggerStatements(): array<int,string>` — triggers that simulate MySQL's `ON UPDATE CURRENT_TIMESTAMP` on `datetime-touch` columns.
- Refactor `DatabaseInstaller`'s `createMySQLTables` / `createSQLiteTables` / `createSQLiteTimestampTriggers` to iterate the compiler output instead of carrying their own heredocs.
- Keep `docker/mysql/init/001_schema.sql` (MySQL's docker-entrypoint cannot run PHP), but treat its DDL section as a generated artifact whose content must equal `SchemaCompiler::mysqlStatements()` concatenated. A unit test asserts the equality on every CI run; the seed `INSERT` block remains hand-written below the DDL section.
- Document the regeneration command (`composer schema:generate`) and the drift test in `docs/development/cli.md` and in the new section of `docs/development/error-codes.md` cross-references (TODO: actual placement decided at PR time).

## Consequences

Positive:

- Adding a column or table is a single PHP edit. Trial 10's friction (touching three files for a Memo entity) becomes one edit + `composer schema:generate`.
- The MySQL `001_schema.sql` file stays valid and bytewise-stable for the docker-entrypoint, so existing volumes initialize identically.
- The new `SchemaCompilerTest::testDockerInitSqlMatchesCompiledOutput` catches drift on every PR.
- The dialect mapping for each column type is one `match` arm in `SchemaCompiler` — auditable on a small surface.

Negative / accepted trade-offs:

- `SchemaCompiler` now owns the full DDL contract. Adding a column type (e.g. `decimal`, `enum`) is one PR that updates `SchemaDefinition`'s type vocabulary, the `mysqlColumn` / `sqliteColumn` match arms, and the regenerated `001_schema.sql`. The price is paid once per new type, not once per new entity.
- Migrations (`ALTER TABLE`) are out of scope for *this* ADR. The current `CREATE TABLE IF NOT EXISTS` flow assumes destructive recreation in dev and operator-managed migrations in prod. **The migration story is addressed by ADR-0009** (`docs/adr/0009-schema-migration-story.md`, derived from `REPORT_commercial_feasibility.md` / Issue #409): a future `cli/schemaDiff.php` reads the same `SchemaDefinition` source and emits operator-applied `ALTER TABLE` statements. ADR-0005's single source is preserved by ADR-0009.
- A few PHP edge cases (column-level `COMMENT`, table-level partitioning, MySQL-specific options like `ROW_FORMAT=DYNAMIC`) are not supported and would need vocabulary extensions if a future bundled table requires them. None of the current schema needs them.

Neutral:

- Backwards compatibility: external apps that build on NeNe and have their own `DataMapperBase` subclasses see no change — the framework still loads the same tables in the same shape. Only the *source* moves from three files to one.
