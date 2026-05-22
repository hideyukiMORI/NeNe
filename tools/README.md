# Development Tools

Single-purpose scripts that make NeNe's day-to-day workflow less typing-heavy. Everything here is reversible — these scripts wrap existing commands, they don't introduce new mandatory steps.

## Quick reference

| Command | What it does | When to use |
| --- | --- | --- |
| `tools/nene-ft-new.sh <topic>` | Bootstrap a new field trial clone (next FT number auto-detected, port offset, `.env`, `.claude/settings.local.json`). | Starting a new FT. |
| `tools/ft-report-new.sh <FT-N> <topic>` | Copy `docs/templates/field-trial-report.md` to `docs/field-trials/YYYY-MM-field-trial-N.md` with date / FT number / topic substituted. | When the trial reaches the report-writing step. Refuses to overwrite. |
| `tools/wait-healthy.sh [port] [timeout]` | Block until `/health` returns 200, with a timeout. | After `docker compose up -d app`, before running curl probes. |
| `tools/trial-status.sh <FT-N>` | List every Issue + PR mentioning a trial number, open and closed. | Closing-out check: "is every spawned Issue closed?" |
| `php tools/error-code-add.php CODE "Message" <http-status> ["notes"]` | Insert a new error code into both `config/error_codes.php` and `docs/development/error-codes.md` atomically. | Adding an error code without the manual two-file dance. |
| `tools/test-http-preflight.php` | Print a one-line summary of the HTTP smoke target before PHPUnit runs. | Called automatically by `composer test:http`. |

## Composer scripts (in `composer.json`)

| Script | What it does |
| --- | --- |
| `composer setup` | First-time DB setup (`cli/setupDatabase.php --env=.env --yes`). |
| `composer schema:generate` | Regenerate `docker/mysql/init/001_schema.sql` from `SchemaDefinition`. |
| `composer schema:check` | Drift check: fail if the generated SQL differs from the snapshot. |
| `composer schema:diff -- --dsn=…` | Operator-applied migration diff (ADR-0009). |
| `composer test` | Unit tests (`phpunit --testsuite unit`). |
| `composer test:http` | HTTP smoke tests (`phpunit --testsuite http`). |
| `composer test:all` | Both suites. |
| `composer analyze` | Static analysis (Phan + baseline). |
| `composer format` | Apply PHP CS Fixer. |
| `composer format:check` | Dry-run PHP CS Fixer (CI gate). |
| `composer check` | `test` + `test:http` + `analyze`. (Excludes `format:check` — see `composer ci`.) |
| **`composer ci`** | **`test` + `test:http` + `analyze` + `format:check`** — runs exactly what CI runs. Use this before pushing. |

### Argument passthrough

Composer 2 forwards everything after `--` to the underlying tool. The most useful case:

```bash
# Run a single HTTP smoke test class
composer test:http -- --filter HttpBearerAuthTest

# Run a single unit test class
composer test -- --filter SchemaDifferTest

# Run a single method
composer test -- --filter 'SchemaDifferTest::testNewIndexEmitsCreateIndex'
```

The preflight in `test:http` silently ignores extra args, so the same pattern works for both suites.

## Shared composer cache

`compose.yaml` references an **external** Docker volume `nene_composer_cache` for composer's package archives. Every NeNe checkout / FT clone reuses the same cache, so a fresh `composer install` is ~5s after the first download instead of ~30-45s.

The volume is created lazily:

- `tools/nene-ft-new.sh` ensures it exists when bootstrapping a clone.
- If `docker compose up` fails with "external volume not found", run `docker volume create nene_composer_cache` once by hand.

Each project's `vendor/` directory stays per-project (composer.lock may differ between clones). What gets cached is the downloaded `.zip` per package version.

## Add a new tool

1. Drop the script in `tools/`. PHP for anything that touches PHP source / config files; bash for shell glue.
2. Add a one-line entry in the **Quick reference** table above.
3. If the script ergonomically wraps a `composer` step, also add a composer script alias in `composer.json`.
4. Keep tools single-purpose. A script that does three unrelated things should be three scripts.
