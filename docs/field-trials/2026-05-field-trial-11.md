# Field Trial 11 — schema-consolidation (ADR-0005 implementation trial)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #357. Parent finding: FT6 F-2 / FT10 F-3 (#350).

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT10 main (all #346–#352 follow-up PRs merged: getLoginUserId promote, normalize-row doc, OpenAPI reusable responses, error-code drift test).
- Clone path: `/home/xi/github/NeNe-FT/ft11-schema-consolidation/`
- Host ports: app=8091, mysql=3318
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 + SQLite parity

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 51 / 51, 135 assertions (FT10 leaves us at 51) |
| `composer test:http` | 23 / 23, 219 assertions, 1 expected skip |

## Goal

FT6 F-2 (`schema lives in three places`) was deferred with the trigger "when a future trial actually trips on the drift". FT10 tripped on it. FT11 is the **implementation trial** that turns the escalated finding into an ADR + working code, not another exploration. The trial answers a single design question (which option from ADR-0005's table to adopt) and then ships.

## Service Built

This is an internal-refactor trial. No new business entity. The "service built" is the new framework subsystem:

- `class/xion/SchemaDefinition.php` — PHP description of every bundled sample table.
- `class/xion/SchemaCompiler.php` — pure-static compiler emitting MySQL/SQLite DDL, SQLite indexes, and SQLite triggers.
- `cli/generateSchemaSql.php` (+ `composer schema:generate` / `composer schema:check`) — regenerator for `docker/mysql/init/001_schema.sql`.
- `tests/Unit/Xion/SchemaCompilerTest.php` — drift gate plus six pinning tests.

## Steps Taken

### 1. ADR direction decision

The pre-trial discussion landed on **Option A: PHP single source** from the three options enumerated in ADR-0005 (Options A: PHP source, B: SQL source + transpile, C: SQL source + generated PHP). The decision points:

- Option B introduces an external SQL transpiler dependency that NeNe's "small framework that runs on `php` + `composer` alone" character explicitly avoids.
- Option C keeps the source as `.sql` but still needs a generator — and a PHP framework whose schema source-of-truth is a `.sql` file is awkward when the rest of the framework (`DataModelBase::$schema`, controllers, mappers) is PHP.
- Option A composes with `class/xion/`'s existing shape: the framework already describes tables in PHP arrays, so extending the pattern is direct.

ADR drafted at `docs/adr/0005-schema-php-single-source.md`.

### 2. SchemaDefinition / SchemaCompiler design

`SchemaDefinition::tables()` returns a structured array per table:

```php
'users' => [
    'columns' => [
        'id' => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'user_id' => ['type' => 'varchar:64'],
        // ...
        'is_deleted' => ['type' => 'bool', 'default' => 0],
    ],
    'unique' => ['users_user_id_unique' => ['user_id']],
],
```

Type vocabulary is intentionally small (`pk-bigint`, `bigint`, `varchar:NN`, `text`, `bool`, `datetime-now`, `datetime-touch`). Every type maps to one `match` arm in `mysqlColumn()` and one in `sqliteColumn()` — auditable on a small surface. New types require both arms plus a vocabulary doc update.

`SchemaCompiler` exposes four pure static methods:

- `mysqlStatements()` → MySQL `CREATE TABLE` strings (semicolon-less, deterministic order).
- `sqliteStatements()` → SQLite `CREATE TABLE` strings (no `KEY` clauses in body).
- `sqliteIndexStatements()` → standalone `CREATE INDEX` statements (SQLite syntactic requirement).
- `sqliteTriggerStatements()` → `BEFORE/AFTER UPDATE` triggers that simulate MySQL's `ON UPDATE CURRENT_TIMESTAMP` for every `datetime-touch` column.

### 3. DatabaseInstaller refactor

`createMySQLTables` / `createSQLiteTables` / `createSQLiteTimestampTriggers` lost their heredoc DDL blocks and became three-line iterations over `SchemaCompiler::*Statements()`. Net change: −99 lines.

### 4. `001_schema.sql` as generated artifact

The MySQL docker-entrypoint cannot run PHP, so the static `.sql` file must stay. The file now opens with a generated-by header, contains DDL byte-equal to the compiler's MySQL output, and preserves the hand-written seed `INSERT` statements below a marker line. A regenerator script (`cli/generateSchemaSql.php`) refreshes the DDL section in place; a `--check` flag verifies drift without writing.

### 5. CI drift gate

`SchemaCompilerTest::testDockerInitSqlMatchesCompiledOutput` reads the committed file, splits at the seed marker, and asserts the DDL section equals `header + concat(SchemaCompiler::mysqlStatements()) + ";\n\n"`. Any future hand-edit to the DDL portion of `001_schema.sql`, or any change to `SchemaDefinition` without running `composer schema:generate`, fails this test. Five sibling pinning tests cover the compiler's table coverage, index emission, and trigger emission.

### 6. Verification

- `composer test`: 57 / 57 (51 existing + 6 new).
- `composer test:http`: 23 / 23 (1 expected skip).
- `composer schema:check`: OK.
- Fresh stack reboot (`docker compose down -v && up -d`) — MySQL volume removed, container init runs the regenerated `001_schema.sql`, `/health` returns `Schema=OK`.
- SQLite path verified in the FT11 clone via `NENE_DB_TYPE=SQLite3 NENE_DB_FILE=ft11-test.db php cli/setupDatabase.php --yes`. Output: `Tables: users, todos / Sample account: admin / admin / Schema=OK`.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Single PHP source describes both `users` and `todos` | yes | yes (`SchemaDefinition::tables()`) | Pass |
| `DatabaseInstaller` no longer carries inline DDL | yes | yes (−99 lines) | Pass |
| `docker/mysql/init/001_schema.sql` is byte-equal to compiler output | yes | yes (test pins it) | Pass |
| Drift detected on hand-edit | yes | yes (`composer schema:check` exits 1) | Pass |
| Fresh MySQL volume initialises with admin seed | yes | yes (verified) | Pass |
| SQLite parity remains | yes | yes (`cli/setupDatabase.php --type=SQLite3` works) | Pass |
| ALTER TABLE / migrations | out of scope | out of scope | n/a |

## Friction Summary

This was an implementation trial driven by an already-deferred finding (FT6 F-2 escalated as FT10 F-3 / #350). The "friction" was the pre-existing 3-way duplication; the trial's job was to remove it. No new F-N findings surfaced *during* the implementation that warrant a follow-up Issue.

| ID  | Location | Severity | Kind | Decision |
| --- | -------- | -------- | ---- | -------- |
| —   | —        | —        | —    | —        |

Notable small-friction observations during implementation, none risen to F-N:

- The seed `INSERT` block in `001_schema.sql` had to be merged manually after the generator overwrote the file the first time. Solved by introducing the seed marker convention — `cli/generateSchemaSql.php` now preserves everything below the marker. Captured in the script's comment header.
- The initial draft used `addPluginsDir`-style API conventions out of habit; the actual Smarty work (FT9) wasn't relevant here. Quickly course-corrected.

## Recommendations

### Immediate

None beyond what ADR-0005 prescribes. The PR (#358) merged the ADR + implementation together.

### Suggested (future trials, not for FT11)

1. **Declarative migrations** — `SchemaDefinition` currently emits `CREATE TABLE IF NOT EXISTS`. A future trial may extend it to express column additions / drops in a way that produces idempotent `ALTER TABLE` statements for both dialects. Out of scope here.
2. **Per-environment seed split** — the seed block in `001_schema.sql` is currently development-oriented (`admin / admin` user). A production deploy bypasses it. A future trial may extract seeds into a separate `seeds/dev.sql` so the schema file is truly DDL-only.

## Aftermath

- ADR-0005 merged (Accepted) as part of #358.
- `class/xion/SchemaDefinition.php` is now the canonical place to add a new bundled table. Future trials (e.g., a Memo entity) edit one PHP file, run `composer schema:generate`, and ship.
- `docs/field-trials/follow-ups.md` does not need an FT11-derived entry (no deferred friction).
- Parent Issue #350 and trial Issue #357 both close via this PR. FT12 can start as soon as the next surface is identified — the remaining candidate from FT8's reflection list is the OpenAPI authoring workflow's downstream effects (templating, swagger UI, contract-test ergonomics) that FT10 only sampled. Defer to maintainer's call.
