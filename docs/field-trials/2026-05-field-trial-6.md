# Field Trial 6 — cli-tooling (initSQLite.php, setupDatabase.php)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #292.

## Date

2026-05-21

## Baseline

- NeNe ref: `247edd1` (post-FT5 main with `docs/review/` self-review checklists from PR #291)
- Clone path: `/home/xi/github/NeNe-FT/ft6-cli-tooling/` (created via `tools/nene-ft-new.sh cli-tooling`; sanity check from PR #283 now blocks the wrong-cwd footgun from FT5)
- Host ports: app=8086, mysql=3313 (auto-offset N=6)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default) and SQLite (initSQLite parity)

### Baseline verification

| Check | Result |
| --- | --- |
| `tools/nene-ft-new.sh cli-tooling` | clone OK; the FT5-introduced sanity check (#283) successfully blocked the older wrong-cwd path |
| `docker compose up -d app` | `/health` `healthStatus=ok` in 7s |
| `composer test` | 45 / 45, 129 assertions |
| `composer test:http` | 21 / 21, 205 assertions, 1 expected skip |

## Goal

Exercise the two CLI installers from the perspective of someone setting up the framework fresh or running it under CI:

1. `cli/initSQLite.php` — the SQLite-only initializer (interactive Y/N prompt)
2. `cli/setupDatabase.php` — the generic installer (`--env=`, `--yes`, `--help`)
3. Their relationship, idempotency, error handling, and discoverability via `composer`

FT1-5 all went through the HTTP runtime. FT6 deliberately stays at the command line.

## Service Built — none

CLI-only trial. No new entity / endpoint / template. The friction surface is the CLI itself, the schema source-of-truth picture, and the docs around them.

## Steps Taken

1. **Baseline verified.** Both suites green.
2. **Read both scripts in full.** `initSQLite.php` is 187 lines (its own SQLite schema + seed); `setupDatabase.php` is 112 lines (defers to `Nene\Xion\DatabaseInstaller`). `DatabaseInstaller` is 300 lines and contains another copy of the schema (both MySQL and SQLite paths inline).
3. **Friction surfaced before the first command was run**: schema is now in **three** places (`docker/mysql/init/001_schema.sql`, `cli/initSQLite.php`, `class/xion/DatabaseInstaller.php`). FT2 F-7 / PR #240 only documented the parity between two of them. → F-2.
4. **Exercise `setupDatabase.php` matrix:**
   - `--help` → clean usage text.
   - No flag + N → "OK. Bye!" exit 0.
   - `--yes` (MySQL default) → installs cleanly, health OK, idempotent on re-run.
   - `-e NENE_DB_TYPE=SQLite3 -e NENE_DB_FILE=nene-via-setup.db --yes` → creates SQLite at the requested path with the same admin seed. → demonstrates F-1 (overlap with initSQLite.php).
   - `--env=/tmp/missing.env --yes` → "Environment file: not loaded", proceeds silently with process env. → F-4.
   - `-e NENE_DB_HOST=bogus-host --yes` → "Database setup failed: SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for bogus-host failed", exit 1. → F-6 (positive).
5. **Exercise `initSQLite.php` matrix:**
   - N at prompt → "OK. Bye!" exit 0.
   - `echo "Y" | php cli/initSQLite.php` → creates `/var/www/html/data/nene.db`. Idempotent on re-run.
   - No `--yes` flag exists → noninteractive callers must pipe Y. → F-3.
   - Runs regardless of `NENE_DB_TYPE`; creates SQLite even when the runtime is MySQL. → consolidated into F-1.
6. **`composer.json` audit**: `scripts` contains `test`, `test:http`, `test:all`, `analyze`, `format`, `format:check`, `check`. No CLI setup shortcut. → F-5.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `setupDatabase.php --help` | clean usage | works | Pass |
| `setupDatabase.php --yes` (MySQL) | tables created, health OK | works, idempotent | Pass |
| `setupDatabase.php --yes` (forced SQLite via env) | SQLite DB created | works, output equivalent to `initSQLite.php` | F-1 |
| `setupDatabase.php --env=/missing --yes` | err or clear fallback | proceeds silently with process env | F-4 |
| `setupDatabase.php` bad DB host | clean error + exit 1 | yes, with `getaddrinfo` detail | F-6 (positive) |
| `initSQLite.php` interactive Y | creates SQLite, idempotent | works | Pass |
| `initSQLite.php` noninteractive | `--yes` flag | absent; requires `echo Y \| ...` | F-3 |
| `initSQLite.php` honors `NENE_DB_TYPE` | only runs in SQLite mode | unconditional; creates SQLite even when runtime is MySQL | consolidated into F-1 |
| Schema source-of-truth audit | one or two locations | **three** (mysql init SQL, initSQLite.php, DatabaseInstaller.php) | F-2 |
| `composer setup` / `composer cli:*` | shortcut exists | not in `composer.json` `scripts` | F-5 |
| `composer test` regression | 45 / 45 still | yes | Pass |
| `composer test:http` regression | 21 / 21 still | yes | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `cli/initSQLite.php` overlaps with `cli/setupDatabase.php` on SQLite path; usage distinction unclear in docs | medium | design-trade-off | document (and consider deprecation) |
| F-2 | Schema duplicated in three places (`docker/mysql/init/001_schema.sql`, `cli/initSQLite.php`, `class/xion/DatabaseInstaller.php`); FT2 F-7 / PR #240 only documented two | medium | design-trade-off | document (escalate to ADR if it bites again) |
| F-3 | `cli/initSQLite.php` has no `--yes` flag; noninteractive callers must `echo Y \| ...` | low | feature-gap | fix-in-framework |
| F-4 | `setupDatabase.php --env=PATH` silently falls back to process env when PATH does not exist | low | docs-gap (or small fix) | fix-in-framework |
| F-5 | `composer.json` has no CLI shortcut (`composer setup`, etc.) | low | feature-gap | fix-in-framework |
| F-6 | `setupDatabase.php` MySQL connect-fail error is clear (host name + `getaddrinfo`) | n/a (positive) | none | no action |
| F-7 | `setupDatabase.php --help` output and the `--env` / `--yes` / `--help` option set are clean | n/a (positive) | none | no action |

### Hypotheses outcome

| # | Hypothesis | Materialized? |
| --- | --- | --- |
| H-A | initSQLite Y/N is CI-unfriendly | yes (F-3) |
| H-B | Usage distinction undocumented | partial — `docs/deployment/server-install.md` lists both in parallel without rationale (F-1) |
| H-C | Idempotency unclear | **no** — both scripts use CREATE IF NOT EXISTS + INSERT WHERE NOT EXISTS and re-run cleanly |
| H-D | `Initialize::init()` web-prerequisite friction | **no** — both scripts call it successfully under CLI |
| H-E | Error messages unhelpful for typical cases | partial — connect-fail is fine (F-6); `--env=` typo is silent (F-4) |
| H-F | No composer shortcut for CLI | yes (F-5) |

## Recommendations

### Immediate (documentation only)

1. **F-1 + F-2** — Add `docs/development/cli.md` (or a new section in `docs/development/docker.md`) that:
   - States `setupDatabase.php` is the canonical installer for both MySQL and SQLite. The one-liner is `php cli/setupDatabase.php --env=.env --yes` (with `NENE_DB_TYPE` deciding the path).
   - Documents that `cli/initSQLite.php` exists as the older SQLite-specific path; new code / deployment guides should use `setupDatabase.php` instead.
   - Names the three schema duplication sites and the rule "any new table requires edits in all three" (extends FT2 F-7 / PR #240 to cover the `DatabaseInstaller` copy).
2. Update `docs/deployment/server-install.md` to lead with `setupDatabase.php` and present `initSQLite.php` as legacy.

### Suggested (small framework change)

3. **F-3** — Add `--yes` and `--help` flags to `cli/initSQLite.php` for symmetry with `setupDatabase.php`. Or, if F-1 deprecation lands first, drop the script entirely.
4. **F-4** — When `--env=PATH` is **explicitly passed** and the path does not exist, `setupDatabase.php` should exit 1 with "Specified env file not found: PATH". When `--env=` is omitted, current "load if exists" behavior remains.
5. **F-5** — Add to `composer.json` `scripts`:

   ```json
   "setup": "@php cli/setupDatabase.php --env=.env --yes"
   ```

   Discoverable via `composer list`. Lowers onboarding cost.

### Trade-offs (ADR-class if it recurs)

6. **F-2 (escalation path)** — Long-term, schema should have a single PHP source (e.g. an array describing tables + columns + FKs) consumed by `DatabaseInstaller` and emitted per-DB-type. The mysql init SQL could be generated from it. ADR-class because it changes how schemas are authored. FT6 does not pull the trigger; document the 3-way parity and revisit if a future trial again surfaces drift.

### Confirmed working (no action)

7. **F-6 / F-7** — `setupDatabase.php` connect-error messages and `--help` output are good. Keep.

## Overall Impression

The CLI surface is small, clean, and mostly works. `setupDatabase.php` is well-designed: `--env` / `--yes` / `--help` options, dual MySQL/SQLite paths, idempotent installs, clear health summary, decent error messages. Once a developer finds it, the experience is good.

The friction is **structural and discoverability-shaped**:

- `initSQLite.php` is a legacy script that duplicates what `setupDatabase.php` already does on the SQLite path. Both scripts coexist in deployment docs without explaining which to use (F-1).
- The same schema is authored in three different files (F-2). FT2 had documented two of them; the third (`DatabaseInstaller`) has been quietly drifting.
- No `composer setup` shortcut means a fresh contributor has to dig through deployment docs to find the install command (F-5).

Operational details (F-3 missing `--yes`, F-4 silent `--env` fallback) are minor.

Three findings are documentation-only, two are small framework fixes, one is positive. No ADR needed for this trial; F-2's potential schema consolidation is the only ADR-class lurker, and it can wait until a future trial confirms the 3-way duplication is actually causing drift in practice.

What FT6 did not exercise: production-mode CLI behavior (with `NENE_APP_ENV=production`), `EnvLoader` edge cases beyond missing-file, `DatabaseInstaller::health()` behavior under partial schema. Those are reasonable FT7 candidates if "deployment story" becomes the next theme.

## Follow-up Issues

To be filed under the FT6 loop (close all before starting FT7):

- F-1 (medium, docs): `setupDatabase.php` is canonical; `initSQLite.php` is legacy.
- F-2 (medium, docs): document the 3-way schema parity (extends FT2 F-7 / PR #240).
- F-3 (low, framework): add `--yes` / `--help` to `initSQLite.php` (or drop it after F-1 lands).
- F-4 (low, framework): `--env=PATH` with missing path should error explicitly.
- F-5 (low, framework): add `composer setup` shortcut.

F-6 / F-7 are positive; no action.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
