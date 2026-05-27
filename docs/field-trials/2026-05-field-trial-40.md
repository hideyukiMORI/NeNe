# Field Trial 40 — BatchResult: partial-success accumulator for batch endpoints

Methodology reference: `docs/field-trials/README.md`. Trial Issue: #FT40.

## Date

2026-05-27

## Baseline

- NeNe ref: post-FT24 main (feat/ft40-batch-operations branch off main).
- PHP: 8.4.21 (in `php:8.4-apache` container)

### Baseline verification

| Check | Result |
| --- | --- |
| `composer install` | 83 packages installed, lock-pinned |
| `vendor/bin/phpunit --testsuite unit` | 222 / 222 tests (pre-FT40), all green |

## Goal

Design and implement `Nene\Xion\BatchResult` — a value object for accumulating per-item results from a batch POST endpoint, enabling **partial success** (207 Multi-Status) semantics. A batch endpoint accepts N items, processes each independently, and must report which succeeded and which failed without short-circuiting the loop.

## What Was Built

### `class/xion/BatchResult.php`

A `final` value object in the `Nene\Xion` namespace. Accumulates per-item outcomes via `addSuccess(int $index, mixed $data = null)` and `addFailure(int $index, string $errorCode, string $errorMessage)`. Exposes convenience predicates (`allSucceeded`, `allFailed`, `isPartialSuccess`) and an `httpStatus()` method that encodes the three-state response rule:

| Condition | HTTP status |
| --- | --- |
| All succeeded (or empty) | 200 |
| Partial success | 207 |
| All failed | 422 |

`toArray()` serialises to `{items: [...], succeeded: N, failed: M}` for direct `json_encode`.

### `tests/Unit/Xion/BatchResultTest.php`

15 pure unit tests. No database, no HTTP. Covers: empty counts, addSuccess/addFailure increments, data preservation, null-data key omission, error code/message preservation, mixed-result predicates, all three `httpStatus()` branches (200/207/422), empty-batch 200, `toArray()` key structure, and index preservation.

### `config/error_codes.php`

Two new entries added:
- `BATCH-TOO-LARGE` (400) — input array exceeds per-endpoint maximum.
- `BATCH-ITEM-FAILED` (422) — a single item in the batch could not be processed.

### `docs/development/error-codes.md`

Catalog table updated with rows for `BATCH-TOO-LARGE` and `BATCH-ITEM-FAILED`, satisfying the `ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable` guard.

### `docs/development/batch-operations.md`

New developer guide. Covers: motivation, `BatchResult` API table, canonical controller pattern (validate size → loop → addSuccess/addFailure → httpStatus() → toArray()), HTTP status table, max-items guard pattern, and related files.

## Steps Taken

### 1. Cold survey

Examined `class/xion/` for existing patterns (ApiResponse, DomainException, HttpResponse). No batch-related helpers existed. The pattern for value objects in `Nene\Xion` is `final class` with no constructor dependencies.

### 2. Design

Decided on a simple accumulator: a private `$items` array, two mutation methods (`addSuccess`, `addFailure`), derived read methods, and `toArray()` for serialisation. The `httpStatus()` method encodes the RFC-standard partial-success rule: 207 when outcomes are mixed, 422 when all fail, 200 otherwise.

Key decision: `addSuccess($index, null)` omits the `data` key entirely rather than emitting `"data": null` — cleaner JSON for callers that do not need a payload back.

### 3. Implementation

Wrote `BatchResult.php` as specified. All methods are pure (no I/O, no global state). The class is `final` to signal that extension is not the intended extension point (callers compose it, not subclass it).

### 4. Tests

Wrote 15 tests covering every public method and all three `httpStatus()` branches. All passed on first run.

### 5. Error codes and docs sync

Added `BATCH-TOO-LARGE` and `BATCH-ITEM-FAILED` to `config/error_codes.php` and the markdown table in `docs/development/error-codes.md`. The `ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable` test confirms both files are in sync.

### 6. Developer guide

Wrote `docs/development/batch-operations.md` with the canonical controller snippet, API table, HTTP status table, and max-items guard pattern.

## Test Results

```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
OK (237 tests, 446 assertions)
```

Phan: exit code 0 (no errors, run with `--allow-polyfill-parser`).

## Findings

| ID  | Location                          | Severity | Kind          | Decision       |
| --- | --------------------------------- | -------- | ------------- | -------------- |
| F-1 | (none)                            | —        | —             | No gaps found  |

No framework friction was encountered. `BatchResult` is a pure value object with no dependencies — it fits cleanly into the existing `Nene\Xion` namespace alongside `ApiResponse`, `DomainException`, and similar helpers.

## Recommendations

### Immediate

None required. `BatchResult` is ready for use by any controller that needs batch POST semantics.

### Suggested

1. **Per-endpoint `MAX_ITEMS` constant** — each controller using batch should define its own maximum and throw `BATCH-TOO-LARGE` before the loop, as shown in `docs/development/batch-operations.md`.
2. **OpenAPI extension** — if a batch endpoint is added to `docs/api/openapi.yaml`, define a response schema for the 207 shape (items array + succeeded/failed counts). The `BatchResult::toArray()` output is the canonical shape.

## Aftermath

- `class/xion/BatchResult.php` and `tests/Unit/Xion/BatchResultTest.php` committed to framework main via PR.
- Two new error codes (`BATCH-TOO-LARGE`, `BATCH-ITEM-FAILED`) in production catalog.
- Developer guide at `docs/development/batch-operations.md`.
