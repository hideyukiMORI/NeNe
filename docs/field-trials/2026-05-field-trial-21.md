# Field Trial 21 — DataMapperBase unit tests

**Date**: 2026-05-27
**Issue**: #443 (deferred from #407)
**PR**: #444 (feat — tests only, no production code change)
**Branch**: `feat/443-datamapperbase-tests`

## Objective

Close the coverage gap on `DataMapperBase` that was explicitly deferred during the #407 PR review. The deferred work was: unit tests for `execute()`, `decorate()`, `assoc()`, `KEY_SID` inheritance rules, `getTableColumn()`, and `getSearchARRAY()` — all of which require PDO test doubles or constant-dependent parsing logic.

## Findings

### F-1 — Mock PDO/PDOStatement works cleanly

PHPUnit's `createMock(PDO::class)` and `createMock(PDOStatement::class)` cover all DB-interaction paths in `DataMapperBase` without a real database or container. The same factory pattern used in `DataModelBaseTest` (`::withDb()` bypass of `__construct`) works here identically.

No special PDO configuration was needed. `execute()` and `executeQuery()` both follow a try/catch(PDOException) pattern that converts to `HttpTermination`, which is testable via `$this->expectException(HttpTermination::class)`.

### F-2 — Guarded `define()` is the right constant strategy for test files

Test files that depend on runtime constants (`APP_DEBUG`, `DB_COLUMN_NAME_CREATED`, `DB_COLUMN_NAME_UPDATED`, `DB_COLUMN_TIMESTAMP`, `DB_NUM_PREFIX`) must guard each `define()` call:

```php
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
```

PHPUnit runs all tests in one process; without guards the second test file that defines the same constant causes a fatal error. This pattern was already established in earlier test files in the suite.

### F-3 — `getSearchARRAY()` handles all delimiter variants correctly

The method correctly normalises full-width comma (`、`), full-width space (`　`), ASCII comma, and ASCII space — including multi-space collapse and leading/trailing trim. 8 test cases (single space, comma, full-width comma, full-width space, multi-space collapse, trim, single keyword, mixed delimiters) all pass as expected.

### F-4 — `getTableColumn()` KEY_SID exclusion is unconditional

Regardless of the `$is_exclude_date` flag, the column matching `KEY_SID` is always excluded. The date-column exclusion (`created_at` / `updated_at`) is controlled by the flag + `DB_COLUMN_TIMESTAMP` constant. Fixture schema (`test_id`, `title`, `created_at`, `updated_at`) + full exclusion leaves exactly `{title}` — confirmed by `assertCount(1, $columns)`.

### F-5 — No Phan issues introduced

The new test file added no Phan findings. Phan baseline remains empty (0 suppressions) after this trial.

## What changed

| File | Change |
|---|---|
| `tests/Unit/Xion/DataMapperBaseTest.php` | **New** — 20 unit tests + 3 fixture classes |

No production code changed. This trial is pure test coverage.

## Test count delta

Before: 178 tests. After: **198 tests, 378 assertions**.

## Related

- `class/xion/DataMapperBase.php` — the class under test
- `docs/field-trials/candidates.md` — candidate entry struck through
- Issue #407 (original deferral note)
