# Field Trial 17 — schema-diff (ADR-0009 implementation trial)

Methodology reference: `docs/field-trials/README.md`. Trial Issue: #417.

## Date

2026-05-23

## Baseline

- NeNe ref: post-#416 main (ADR-0009 drafted, test count at 128).
- Clone path: `/home/xi/github/NeNe-FT/ft17-schema-diff/`
- Host ports: app=8097, mysql=3324
- PHP: 8.4.21
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `composer install` | 63 packages |
| `/health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 128 / 128 |
| `composer test:http` | 24 / 24, 1 expected skip |

## Goal

**Implement ADR-0009** (Option C: operator-applied `cli/schemaDiff.php`). The ADR was drafted in #416 with explicit "implementation is a separate trial". FT17 is that trial.

The ADR pinned the design:

- Read `SchemaDefinition::tables()`
- Introspect the live DB (MySQL or SQLite)
- Emit add-only DDL (`ALTER TABLE … ADD COLUMN`, `CREATE TABLE …`) to stdout
- Warn (stderr, no SQL) on destructive shapes (drop / rename / type change)
- **Never apply**

## Service Built

This is a tool trial, not a feature trial. The "service built" is the CLI plus its diff engine.

- `Nene\Xion\SchemaDiffer` — pure-static diff engine (DB-agnostic). 10 unit cases.
- `cli/schemaDiff.php` — PDO + stdout wrapper. MySQL via `INFORMATION_SCHEMA`, SQLite via `PRAGMA table_info()`.
- `SchemaCompiler` per-table helpers (`mysqlCreateTable` / `sqliteCreateTable` / `mysqlColumn` / `sqliteColumn`) promoted from `private static` to `public static` so `SchemaDiffer` can compose statements without duplicating the dialect mapping.
- `composer schema:diff` alias for ergonomics.

## Steps Taken

### 1. Cold survey

`SchemaDefinition::tables()` already exists from ADR-0005 (FT11). `SchemaCompiler` knows how to render full DDL for a table but the per-table helpers were private. No diff or introspection code anywhere in the framework.

### 2. Design

`SchemaDiffer::diff(array $live, array $definition, string $driver)` returns:

```
[
    'newTables'  => [tableName => 'CREATE TABLE …;'],
    'newColumns' => [['table' => …, 'column' => …, 'sql' => 'ALTER TABLE …;']],
    'warnings'   => ['column `users.legacy_phone` exists in the live database but not in SchemaDefinition — drop SQL must be hand-written (ADR-0009 destructive-op rule).'],
    'inSync'     => bool,
]
```

CLI calls `SchemaDiffer::diff(...)`, emits the new SQL to **stdout** and the warnings + headers to **stderr**. Operators redirect stdout to a file (`> migration.sql`) and review the file before piping it to `mysql`.

### 3. Promote four `SchemaCompiler` helpers

`mysqlCreateTable` / `sqliteCreateTable` / `mysqlColumn` / `sqliteColumn` are pure functions of `(name, table_or_column_spec)`. They were already used internally by `mysqlStatements()` / `sqliteStatements()`. Promoting to `public static` adds no risk and gives `SchemaDiffer` the per-row primitives it needs.

### 4. Implementation

`SchemaDiffer` is pure-static, ~100 lines. The diff is a straightforward "in definition but not in live → emit add-SQL; in live but not in definition → emit warning". No type-comparison logic in v1 — ADR-0009's scope keeps that out.

`cli/schemaDiff.php` handles argv parsing (getopt), PDO connection, driver detection (`PDO::ATTR_DRIVER_NAME`), introspection (MySQL via `INFORMATION_SCHEMA.COLUMNS`, SQLite via `PRAGMA table_info()`), and stdout/stderr separation.

### 5. Live verification (MySQL only — SQLite path covered by unit tests)

```
$ php cli/schemaDiff.php --dsn=mysql:host=mysql --user=nene --pass=nene
-- schema is in sync with SchemaDefinition (driver=mysql)
# exit 0

$ mysql -e "ALTER TABLE todos DROP COLUMN is_completed"
$ php cli/schemaDiff.php …
-- new column: todos.is_completed
ALTER TABLE todos ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0;

$ php cli/schemaDiff.php … 2>/dev/null | mysql …
$ php cli/schemaDiff.php …
-- schema is in sync …  (round-tripped clean)

$ mysql -e "DROP TABLE todos"
$ php cli/schemaDiff.php …
-- new table: todos
CREATE TABLE IF NOT EXISTS todos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  …
  CONSTRAINT todos_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

$ mysql -e "ALTER TABLE users ADD COLUMN legacy_phone VARCHAR(64) NOT NULL DEFAULT ''"
$ php cli/schemaDiff.php …
-- warning: column `users.legacy_phone` exists in the live database but not in SchemaDefinition — drop SQL must be hand-written (ADR-0009 destructive-op rule).
-- (no stdout SQL emitted)
```

Five live scenarios, all produce expected output.

### 6. Unit tests

`SchemaDifferTest` ships 10 cases:

- in-sync
- new table → CREATE TABLE (MySQL + SQLite)
- new column → ALTER TABLE (MySQL + SQLite)
- bool default propagation (regression for "is_archived DEFAULT 1" use case)
- extra column → warning, no SQL
- extra table → warning, no SQL
- multiple warnings accumulate
- `inSync` flag is false when any add is present

Total tests: 128 → 138 (+10).

### 7. Friction inventory

- **F-1** (low informational): `SchemaCompiler` per-table helpers were private. Promoted to public. No behavior change.
- **F-2** (low informational): MySQL `INFORMATION_SCHEMA` returns uppercase column keys; SQLite `PRAGMA` returns lowercase. Handled inline in each introspection function — no shared normalization layer needed.
- **F-3** (low ergonomics): `composer schema:diff` alias missing. Added.
- **F-4** (low ergonomics): `--help` output is a one-line pointer. Acceptable for v1, queue doc PR follow-up.
- **F-5** (medium feature-gap): **Add-index path not yet emitted.** ADR-0009 listed Add column / Add table / Add index, but the trial implemented add column and add table only. A new index added to `SchemaDefinition` for an existing table would not be picked up by the current CLI (would falsely report in-sync). Scoped as a small follow-up Issue (not a re-opened trial).

## Results

| Acceptance criterion (Issue #417) | Status |
| --- | --- |
| in-sync MySQL → "schema is in sync" exit 0 | Pass |
| new column → `ALTER TABLE … ADD COLUMN …` with default | Pass |
| new table → `CREATE TABLE …` with FK + index | Pass |
| extra column in live → stderr warning, no stdout SQL | Pass |
| SQLite mode | Pass (unit-tested; live verify not run because no SQLite DSN was wired in clone) |
| Unit tests 5+ cases | Pass (10 cases) |
| `composer test` / `composer analyze` / `composer format:check` all green | Pass |

## Friction Summary

| ID  | Location                                       | Severity | Kind            | Decision           |
| --- | ---------------------------------------------- | -------- | --------------- | ------------------ |
| F-1 | `class/xion/SchemaCompiler.php` (visibility)   | low      | informational   | document           |
| F-2 | `cli/schemaDiff.php` (case mapping)            | low      | informational   | document inline    |
| F-3 | `composer.json` (alias)                        | low      | ergonomics      | fix-in-framework   |
| F-4 | `cli/schemaDiff.php` (help)                    | low      | ergonomics      | defer to docs PR   |
| F-5 | `class/xion/SchemaDiffer.php` (add-index)      | medium   | feature-gap     | follow-up issue    |

## Recommendations

### Immediate (feat PR)

1. Port `SchemaDiffer` + `cli/schemaDiff.php` + `SchemaCompiler` promotion + `composer schema:diff` alias + unit tests. ADR-0009's "Implementation tracking" section gets a cross-link.

### Immediate (docs PR)

1. New `docs/development/schema-migrations.md` covering the workflow, the out-of-scope list, examples for MySQL + SQLite, the operator review-before-apply checklist.
2. Update ADR-0005 / ADR-0009 cross-links to point at the new doc.
3. AGENTS.md Read-First link.
4. Expand `cli/schemaDiff.php --help` output.

### Follow-up Issue (F-5)

Add-index path. Small, well-scoped. Extend MySQL introspection with `INFORMATION_SCHEMA.STATISTICS`, SQLite with `PRAGMA index_list()` + `PRAGMA index_info(name)`. `SchemaDiffer` emits `CREATE INDEX` for new indexes only.

## Aftermath

- ADR-0009 closed by the FT17 feat PR (ADR addressed by an actual implementation).
- `docs/field-trials/follow-ups.md` does not need an FT17-derived entry (F-5 is filed as a dedicated Issue).
