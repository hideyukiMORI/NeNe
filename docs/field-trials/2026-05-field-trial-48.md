# Field Trial 48 — Offset Pagination (`OffsetPage` + `PaginationHelper`)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`.

## Date

2026-05-27

## Baseline

- NeNe ref: `4df4757` (post-FT47 main)
- PHP: 8.4.21 (host)
- Database: n/a — pure unit trial, no DB required
- Other relevant tooling: PHPUnit 10.5, Phan (requires `ast` extension — skipped, exit 0)

## Goal

Verify that `Nene\Xion\OffsetPage` and `Nene\Func\PaginationHelper` can be introduced as a complement to `CursorPage` (FT25) to support page-number–based navigation for admin UIs and search results. Trial is framework-side only — no service clone required.

## Service Built

- Name: n/a (framework-side addition)
- Domains: n/a
- Surface: 2 new classes, 2 new test files, 1 development guide
- DB tables: n/a

## Steps Taken

### 1. Survey existing pagination surface

Checked `class/xion/` for any existing `*Page*` class — none found. Confirmed `CursorPage` does not exist yet in the source tree either (FT25 is a future milestone). The new classes introduce the pattern from scratch.

Checked `class/func/` — only `Text.php` present. `PaginationHelper` fits naturally here as a pure static math utility.

### 2. Author `OffsetPage` value object

Created `class/xion/OffsetPage.php` as a `final readonly` class. Computed properties (`$totalPages`, `$hasPrev`, `$hasNext`) are set in the constructor body — PHP 8.4 `readonly` classes do not allow property initialization outside the constructor.

**Finding (F-1)**: PHP 8.4 allows `final readonly class` but the `readonly` modifier on a `readonly class` property is redundant. The spec says all properties of a `readonly` class are implicitly `readonly`. The docblock `@param list<mixed>` Phan annotation on `$items` (typed `array`) works at runtime but Phan would flag the shape mismatch without the `ast` extension present to enforce it — acceptable at this stage.

### 3. Author `PaginationHelper` static utilities

Created `class/func/PaginationHelper.php` with four static methods:

- `offset()` — SQL OFFSET, never negative.
- `totalPages()` — `ceil` division, 0 on bad input.
- `clamp()` — bounds-checks a page number.
- `window()` — paginator UI page list with `0` as ellipsis sentinel.

No surprises. All four are pure functions.

### 4. Write unit tests — 30 tests, 48 assertions

`tests/Unit/Xion/OffsetPageTest.php` — 14 tests.
`tests/Unit/Func/PaginationHelperTest.php` — 16 tests (two extra covering negative `offset` input and verifying the middle-page window slice).

`vendor/bin/phpunit --testsuite unit` — 252 tests, 460 assertions, 0 failures.

### 5. Static analysis

`vendor/bin/phan --no-progress-bar` exits 0. The `ast` extension is absent on the host, so Phan emits a setup reminder but performs no file analysis. No framework-breaking issues expected given the classes are pure value objects with no external dependencies.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `OffsetPage` computes `totalPages` from constructor args | `ceil(total/perPage)` | Correct | Pass |
| `OffsetPage::hasPrev` / `hasNext` flags | Correct booleans on first, middle, last pages | Correct | Pass |
| `OffsetPage::toArray()` envelope | `{items, pagination{6 keys}}` | Correct shape | Pass |
| `PaginationHelper::offset()` never negative | `offset(0, 10) === 0` | 0 | Pass |
| `PaginationHelper::window()` produces ellipsis sentinels | `[1,0,3..7,0,10]` for page 5 of 10 | Correct | Pass |
| Full unit suite unaffected | 252 tests pass | 252 pass | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `class/xion/OffsetPage.php` | low | design-trade-off | keep-legacy |

F-1: The `readonly array $items` property carries a `@param list<mixed>` annotation. Phan cannot enforce the `list<>` shape without the `ast` extension present in the host environment. This is a tooling gap in local development, not a framework defect.

## Recommendations

### Immediate (documentation only)

1. **F-1 — Phan ast extension**: Add a note to `docs/development/testing.md` (or `CONTRIBUTING.md`) that Phan requires `ext-ast` to perform full analysis; without it the tool exits 0 without analysing any files.

### Suggested (small framework or template change)

None.

### Trade-offs (needs ADR or discussion)

None.

## Overall Impression

The addition was frictionless. Both classes are pure value objects / static helpers with zero external dependencies, so they dropped in cleanly. The `window()` algorithm needed careful thought for the edge cases (first page, last page, small total), but the test suite caught every variant on first run. The pattern complements `CursorPage` well: offset for numbered admin tables, cursor for live feeds.

## Follow-up Issues

- [ ] F-1 — Document Phan `ast` extension requirement → Issue TBD

## Reminder

This report is committed to a public repository. It omits secrets, raw keys, production endpoints, and confidential prompts.
