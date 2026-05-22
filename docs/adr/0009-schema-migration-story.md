# ADR 0009: Schema Migration Story

## Status

Accepted

## Context

ADR-0005 (`docs/adr/0005-schema-php-single-source.md`) made `class/xion/SchemaDefinition.php` the canonical schema source and `SchemaCompiler` the MySQL / SQLite DDL emitter. The ADR explicitly carved out **migrations (`ALTER TABLE`)** as out of scope, with the trigger condition: "future trial that drifts on this surface escalates the concern."

The trigger condition is now satisfied by an external evaluation surface — `REPORT_commercial_feasibility.md` (PR #401) — which named schema migrations as the **single largest practical concern** for commercial NeNe deployments:

> マイグレーション機構がない（最大の実務懸念）— スキーマ変更は `ALTER TABLE` を手動実行。ADR-0005 でも「マイグレーションは対象外」と明記されています。本番運用中にカラム追加・変更があるたびに、オペレータが手で SQL を叩く必要があります。

A small commercial deploy with quarterly schema changes hand-rolls the `ALTER TABLE` against production. That works once but does not scale to "many deploys" or "long-lived multi-version pipelines" — the operator-side cognitive load and rollback risk grow per migration.

The trial-friction surface is in flight too — FT11 found that the three-file schema duplication friction (DataMapperBase + docker/mysql/init + SQLite initializer) was real enough to merit ADR-0005. Migrations are the natural next surface to ADR.

### Constraints

1. **NeNe philosophy**: small framework, env-driven config, no large new abstractions.
2. **Backward compatibility**: existing operators who run `cli/setupDatabase.php` (MySQL) or `cli/initSQLite.php` (SQLite) must not regress. Bundled `001_schema.sql` for the Docker entrypoint must continue to work.
3. **No graceful rollback fantasy**: even Phinx / Liquibase ship with caveats around rollback; the framework should not pretend it can `ALTER TABLE DROP COLUMN` losslessly.
4. **Single source preserved**: ADR-0005's `SchemaDefinition` stays the source of truth. Whatever migration mechanism this ADR adopts must read from `SchemaDefinition` or extend it; it cannot grow a parallel schema source.

### Options considered

| Option | What it is | Trade-off |
| --- | --- | --- |
| **A — External Phinx / Liquibase** | Operator installs a separate tool, NeNe is unaware. | Smallest framework change, but the integrator must learn Phinx semantics, set up its config, and bridge its idea of "current schema" with NeNe's `SchemaDefinition`. Two sources of truth in practice. |
| **B — Versioned `SchemaDefinition` + auto-generated `ALTER TABLE`** | Add a `version` field to each table in `SchemaDefinition`; `SchemaCompiler` diffs the running DB introspection against the definition and emits `ALTER TABLE` for new columns. | Real implementation work. dialect-specific edge cases (column rename, type change, NOT NULL with default backfill) need careful handling. |
| **C — `cli/schemaDiff.php` + operator-applied SQL** | A new CLI compares the live DB schema with `SchemaDefinition::buildTables()` and emits the `ALTER TABLE` statements needed to converge. The operator runs them by hand (or pipes to `mysql` / `sqlite3`). | Smallest viable. NeNe stays out of "apply" so destructive operations remain the operator's call. The integrator gets a single command that says exactly what needs to change. |

Option C wins for FT15-era NeNe because:

- **Aligns with NeNe's "operator-side concerns stay operator-side"** stance (production-deployment env matrix philosophy, ADR-0004 redirect hook, ADR-0008 Bearer token).
- **Single source preserved**: `cli/schemaDiff.php` reads `SchemaDefinition::buildTables()` (the same source `SchemaCompiler` already uses). No new schema-source class is introduced.
- **No false promise of rollback**: the operator sees the diff, runs it, owns the consequence. NeNe never claims it can revert.
- **Phinx isn't ruled out**: an operator who wants Phinx-style ergonomics can still install Phinx and use it side-by-side. C does not preclude A.

Option B is the right shape for a future NeNe + ADR — but it requires diff resolution heuristics (rename detection, type-change semantics, default-value backfill) that grow the framework's surface significantly. Premature for the current scale of NeNe deployments.

## Decision

- Introduce **`cli/schemaDiff.php`**: a CLI that introspects the live database (MySQL via `INFORMATION_SCHEMA`, SQLite via `PRAGMA table_info`), compares against `Nene\Xion\SchemaDefinition::buildTables()`, and emits the `ALTER TABLE` statements that would converge the live DB to the definition.
- Output is **idempotent** when the schemas match (no `ALTER` emitted; exit code 0 with a "schema is in sync" notice).
- The CLI **does not apply** statements automatically. Operators redirect the output to a file, review, and `mysql < schemaDiff.sql` (or equivalent for SQLite). This keeps destructive operations explicit.
- Diff coverage in the **initial** implementation:
  - **Add column** — `ALTER TABLE ... ADD COLUMN ...` with default value from the definition.
  - **Add table** — `CREATE TABLE` from `SchemaCompiler::mysqlStatements()` / `sqliteStatements()`.
  - **Add index** — `CREATE INDEX`.
- Diff coverage **explicitly out of scope** initially (CLI prints a warning when these are detected):
  - **Drop column** / **Drop table** / **Drop index** (destructive — operator must hand-write).
  - **Column type change** (semantics depend on data; operator must hand-write with backfill).
  - **Column rename** (cannot disambiguate from "drop old + add new" without metadata).
  - **Constraint changes** (UNIQUE / FOREIGN KEY / CHECK additions).
  - **Default-value-only changes**.
- The Docker MySQL entrypoint `docker/mysql/init/001_schema.sql` continues to be regenerated by `composer schema:generate` (ADR-0005). For ongoing production migrations, operators run `cli/schemaDiff.php` after every release that touches `SchemaDefinition`.
- A short doc, `docs/development/schema-migrations.md`, explains the workflow and the explicit "out of scope" list.

## Consequences

Positive:

- Adding a column becomes: edit `SchemaDefinition.php` → `composer schema:generate` (regenerates `001_schema.sql`) → on the next deploy, the operator runs `php cli/schemaDiff.php --dsn=mysql:host=...` to see the `ALTER TABLE` statement → apply.
- The diff output is small, reviewable, and version-control-friendly. Operators can paste it into release notes.
- No new dependency. No new abstraction surface beyond a single CLI file.
- ADR-0005's single source is preserved — `SchemaDefinition` is read by both the compiler and the diff CLI.
- Operators who want Phinx ergonomics can still adopt it; C does not block A.

Negative / accepted trade-offs:

- **Operator runs the SQL.** No auto-apply means a forgotten step results in a runtime error (controller hits a column that doesn't exist). The trade-off is preferred over silent schema mutation in production.
- **Destructive ops are hand-written.** Drop column, drop table, type change, rename — operator must author the SQL with awareness of data semantics. The CLI surfaces these gaps clearly (warnings in the output) so they are not silently overlooked.
- **No multi-version migration history.** Phinx-style "migration 0042 ran on 2026-02-01" tracking is not provided. Operators who need that level can layer Phinx on top of NeNe (the schema source is still `SchemaDefinition`); ADR-0009 does not preclude that.
- **Diff heuristics are conservative.** When in doubt, the CLI warns and refuses to emit. Operator escalation is expected for non-trivial changes.

Neutral:

- The CLI runs offline against the operator's existing DB credentials. No network round-trips beyond the DB query.
- The output format is line-by-line SQL with `-- comment` headers explaining each `ALTER`. Operators can `diff` two runs to see what changed between releases.

## Implementation tracking

- This ADR is **drafted in parallel with #409** (the eval-report-derived issue) but the **implementation lands as a separate trial / PR**, not in this ADR's branch. The next FT (FT17 candidate: "schema-diff CLI") picks up the implementation when the trial-clone bandwidth is available.
- A future ADR may extend this surface to (a) include Phinx as an optional dep, or (b) move to Option B (auto-generated `ALTER TABLE` via versioned `SchemaDefinition`) if the operator-side friction surfaces again.
- ADR-0005's Consequences section gets a cross-link to ADR-0009 ("Schema migrations: addressed by ADR-0009").
