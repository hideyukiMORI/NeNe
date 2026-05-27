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
| `php tools/make-xion.php ClassName` | Scaffold `class/xion/ClassName.php` + `tests/Unit/Xion/ClassNameTest.php` with PDO injection skeleton; auto-registers in `class/xion/INDEX.md`. | Starting a new Xion helper class. |
| `php tools/xion-index.php` | Regenerate `class/xion/INDEX.md` from PHPDoc. Updates descriptions, removes stale entries, adds new classes to Uncategorized. | After editing a Xion class PHPDoc description. |
| `php tools/ft-done.php FT265 ClassName "desc" 712` | Update `docs/todo/current.md`, `docs/field-trials/candidates.md`, and `docs/roadmap.md` in one shot after merging an FT PR. | After every merged Xion FT PR. |
| `tools/test-http-preflight.php` | Print a one-line summary of the HTTP smoke target before PHPUnit runs. | Called automatically by `composer test:http`. |

## Composer scripts (in `composer.json`)

### Pre-push checklist

```bash
composer precommit    # format → analyze (full Phan) → unit tests — run before every push
```

`composer precommit` is the right command for day-to-day local verification. It runs everything that can fail CI, in the order that catches problems fastest.

`composer ci` runs the full CI suite including HTTP smoke tests (requires Docker). Use it when changing HTTP-layer code or verifying a release candidate; it is slower and not needed for every push.

### All scripts

| Script | What it does |
| --- | --- |
| `composer setup` | First-time DB setup (`cli/setupDatabase.php --env=.env --yes`). |
| `composer schema:generate` | Regenerate `docker/mysql/init/001_schema.sql` from `SchemaDefinition`. |
| `composer schema:check` | Drift check: fail if the generated SQL differs from the snapshot. |
| `composer schema:diff -- --dsn=…` | Operator-applied migration diff (ADR-0009). |
| `composer test` | Unit tests (`phpunit --testsuite unit`). |
| `composer test:http` | HTTP smoke tests (`phpunit --testsuite http`). |
| `composer test:all` | Both suites. |
| `composer analyze` | Full static analysis — Phan + baseline (~40 s). Same as CI. |
| `composer analyze:file -- a.php b.php` | Targeted Phan analysis for one or a few files (~14 s). Use before pushing a new Xion class. |
| `composer format` | Apply PHP CS Fixer. |
| `composer format:check` | Dry-run PHP CS Fixer (CI gate). |
| **`composer precommit`** | **format → analyze → test** — run before every push. |
| `composer make:xion -- ClassName` | Scaffold a new Xion class + test file + INDEX registration. |
| `composer xion:index` | Regenerate `class/xion/INDEX.md` from PHPDoc. |
| `composer ft:done -- FT265 Foo "desc" 712` | Update three tracking docs after merging an FT PR. |
| `composer check` | `test` + `test:http` + `analyze`. |
| `composer ci` | `test` + `test:http` + `analyze` + `format:check` — full CI suite including Docker HTTP tests. |

### Argument passthrough

Composer 2 forwards everything after `--` to the underlying tool:

```bash
# Run a single HTTP smoke test class
composer test:http -- --filter HttpBearerAuthTest

# Run a single unit test class
composer test -- --filter SchemaDifferTest

# Run a single method
composer test -- --filter 'SchemaDifferTest::testNewIndexEmitsCreateIndex'

# Analyse specific files (fast Phan, ~14 s)
composer analyze:file -- class/xion/Foo.php tests/Unit/Xion/FooTest.php
```

## Shared composer cache

`compose.yaml` references an **external** Docker volume `nene_composer_cache` for composer's package archives. Every NeNe checkout / FT clone reuses the same cache, so a fresh `composer install` is ~5s after the first download instead of ~30-45s.

The volume is created lazily:

- `tools/nene-ft-new.sh` ensures it exists when bootstrapping a clone.
- If `docker compose up` fails with "external volume not found", run `docker volume create nene_composer_cache` once by hand.

Each project's `vendor/` directory stays per-project (composer.lock may differ between clones). What gets cached is the downloaded `.zip` per package version.

## Add a new tool

1. Drop the script in `tools/`. PHP for anything that touches PHP source / config files; bash for shell glue.
2. Add a one-line entry in the **Quick reference** table above.
3. Add a row to the **All scripts** table if it wraps a `composer` step.
4. Keep tools single-purpose. A script that does three unrelated things should be three scripts.
