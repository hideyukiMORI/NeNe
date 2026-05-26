# Field Trial 24 — CLI Command Framework

**Date:** 2026-05-27
**Theme:** Abstract `Command` base class for all CLI scripts — shared option parsing, `--help`, and output helpers
**Issue:** #456
**PR:** #457

---

## What was built

A `Nene\Xion\Command` abstract base class that all CLI scripts now extend. The four existing CLI scripts (`setupDatabase.php`, `initSQLite.php`, `generateSchemaSql.php`, `schemaDiff.php`) were refactored from 686 total lines of procedural code with duplicated `getopt` + help + confirm patterns into four thin shells (~20 lines each) backed by four focused command classes.

### New framework class

**`class/xion/Command.php`** — abstract base for CLI commands:

| Method | Visibility | Purpose |
|---|---|---|
| `execute(): int` | public | Parse options, handle `--help`, dispatch to `handle()` |
| `optionDefinitions(): array` | protected | Declare `--option` names and arities |
| `helpText(): string` | protected abstract | Usage string for `--help` |
| `handle(array $options): int` | protected abstract | Business logic; returns exit code |
| `line(string $msg = ''): void` | protected | Write a line to STDOUT |
| `error(string $msg): void` | protected | Write a line to STDERR |
| `write(string $msg): void` | protected | Write to STDOUT without trailing newline |
| `confirm(string $question): bool` | protected | Interactive Y/N prompt |
| `parseArguments(array $longOpts): array` | protected | Wraps `getopt()`, overridable in tests |

### Command classes (new, in `class/xion/`)

| Class | Thin shell | Key change |
|---|---|---|
| `SetupDatabaseCommand` | `cli/setupDatabase.php` | Logic extracted, behaviour unchanged |
| `InitSqliteCommand` | `cli/initSQLite.php` | **Hardcoded DDL removed**; now uses `DatabaseInstaller::install()` + `SchemaCompiler` |
| `GenerateSchemaSqlCommand` | `cli/generateSchemaSql.php` | Logic extracted, behaviour unchanged |
| `SchemaDiffCommand` | `cli/schemaDiff.php` | Introspect helpers moved to private methods |

### Tests

**`tests/Unit/Xion/CommandTest.php`** — 16 tests, 20 assertions covering:
- `execute()` exit code pass-through
- `--help` flag: returns 0, outputs help text, does not call `handle()`
- Option forwarding: none/optional/required arities
- Multiple options all forwarded
- `line()`, `write()`, `error()` output helpers
- `parseArguments()` hook makes testing possible without real argv

---

## Findings

### F-1 — `initSQLite.php` hard-coded DDL that duplicated SchemaCompiler (medium, fixed)

The original `cli/initSQLite.php` contained 100+ lines of hard-coded `CREATE TABLE`, `CREATE INDEX`, and `CREATE TRIGGER` SQL that duplicated what `class/xion/SchemaCompiler` already generates dynamically. This was the third copy of the schema (FT6 F-2 identified two copies at the time; `SchemaCompiler` has since been made the source of truth for `DatabaseInstaller`).

**Fix:** `InitSqliteCommand::handle()` calls `DatabaseInstaller::install()` after forcing `NENE_DB_TYPE=SQLite3`. The SchemaCompiler is now the single DDL source for all three consumers (MySQL entrypoint, `setupDatabase.php`, `initSQLite.php`).

### F-2 — `initSQLite.php` had no `--yes` flag (low, fixed as side-effect)

FT6 F-3 noted that `initSQLite.php` had no `--yes` flag — non-interactive callers had to pipe `echo "Y" |`. The refactored `InitSqliteCommand` now accepts `--yes` via `optionDefinitions()`, making it consistent with `setupDatabase.php`.

### F-3 — `getopt()` is not injectable without a hook (design note)

PHP's `getopt()` reads from the real process argv; it cannot be mocked by setting `$_SERVER['argv']`. This made the option-parsing tests impossible without an overridable hook.

**Fix:** `parseArguments(array $longOpts): array` is a protected method in `Command`. It wraps `getopt()` and is the only code path that touches the real argv. Tests override it via `RecordingCommand` (a named fixture class in `CommandTest.php`).

The `RecordingCommand` pattern is documented as a convention: test subclasses of `Command` override `parseArguments()` to inject specific options; they never manipulate the real process argv.

### F-4 — Line counts (positive)

| Script | Before (lines) | After (lines) | Command class (lines) |
|---|---|---|---|
| `setupDatabase.php` | 119 | 20 | 126 |
| `initSQLite.php` | 202 | 20 | 93 |
| `generateSchemaSql.php` | 75 | 22 | 96 |
| `schemaDiff.php` | 290 | 20 | 273 |
| **Total** | **686** | **82** | **588** |

The CLI shell files are now ~3× shorter. The command classes are independently testable. Total line count increased (more explicit code), but each file now has a single responsibility.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 222 tests, 412 assertions — OK |
| Phan | 0 errors (exit 0) |
| PHP syntax | All 9 files: no errors |
| `--help` smoke test | All 4 scripts: correct output |
| `setupDatabase.php --yes` | MySQL setup: OK |
| `initSQLite.php --yes` | SQLite created via DatabaseInstaller: OK |
| `generateSchemaSql.php --check` | Schema in sync: OK |

---

## How to add a new CLI command

1. Create `class/xion/MyCommand.php` extending `Nene\Xion\Command`
2. Implement `optionDefinitions()`, `helpText()`, `handle()`
3. Create `cli/myCommand.php` as a thin shell: `exit((new MyCommand())->execute());`
4. Write `tests/Unit/Xion/MyCommandTest.php` using `RecordingCommand` pattern if needed

See `docs/development/cli.md` for the full guide.

---

## Related

- Issue: #456
- FT6 (cli-tooling): identified initSQLite/setupDatabase overlap (F-1) and missing --yes (F-3)
- `docs/development/cli.md` — CLI guide (to be updated)
- `class/xion/Command.php` — base class
