# Field Trial 46 — file-upload (fluent `FileUpload` helper)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`.

## Date

2026-05-27

## Baseline

- NeNe ref: post-FT45 main (commit `133b2cf` — NENE2 parity roadmap FT25–FT36 added)
- PHP: 8.4.21
- Test suite: 222 passing before this trial

## Goal

Add a fluent `FileUpload` helper class (`Nene\Xion\FileUpload`) that wraps a single `$_FILES` entry and provides a chainable validation API (`require → validateSize → validateMime → moveTo`). Unlike the existing `UploadedFile`, which throws `DomainException` (requiring the top-level handler to map it to a response), `FileUpload` throws `HttpTermination` directly with the correct HTTP status, keeping controllers thin.

Error codes `UPLOAD-FILE-REQUIRED` (400), `UPLOAD-TOO-LARGE` (413), and `UPLOAD-MIME-REJECTED` (415) were already registered in `config/error_codes.php`.

## Service Built

Not a full service — this trial targets a single framework-layer class.

- New file: `class/xion/FileUpload.php`
- New test: `tests/Unit/Xion/FileUploadTest.php` (10 tests, 17 assertions)
- Updated doc: `docs/development/file-uploads.md` — added `FileUpload` API reference, controller pattern, and destination directory guidance

## Steps Taken

### 1. Survey existing upload helpers

Read `class/xion/UploadedFile.php` and `tests/Unit/Xion/UploadedFileTest.php`.

Key observations:

- `UploadedFile` is constructed directly from a `$_FILES` entry array; it uses `finfo` for MIME detection but only inside `mime()` (lazy, cached).
- `validate()` throws `DomainException('UPLOAD-FILE-REQUIRED')`, which the top-level handler converts to the JSON failure envelope via `ApiResponse::failure()`.
- `moveTo()` returns `bool` and requires the caller to compute the full target path including filename.

**Finding (F-1)**: `UploadedFile::moveTo()` takes a full path, not a directory. Callers must generate the filename themselves, which makes it easy to accidentally preserve the client filename and enable path-traversal. The new `FileUpload::moveTo(dir)` generates a random hex filename by default, reversing the burden.

### 2. Survey HttpTermination / JsonResponder usage patterns

Checked how other xion classes throw `HttpTermination`:

- `Dispatcher` uses `JsonResponder::responseArray((new ApiResponse())->failure($errorCode))` — this resolves the HTTP status from the error-codes catalog automatically.
- `RedirectGuard` uses `HttpResponse::html('Forbidden', 403)` — appropriate for HTML guards, not REST.

Adopted the `JsonResponder::responseArray((new ApiResponse())->failure(...))` pattern for all three throw sites in `FileUpload`, keeping behavior consistent with `Dispatcher`.

### 3. Write `FileUpload` class

Created `class/xion/FileUpload.php` with:

- `require()` — static factory, throws 400 on missing or errored upload.
- `load()` — static factory, returns `null` instead of throwing.
- `validateSize()` — fluent, throws 413.
- `validateMime()` — fluent, throws 415. Uses the `finfo`-detected MIME stored at construction time; client-supplied `$_FILES[]['type']` is never trusted.
- `moveTo()` — moves to a directory, generates `bin2hex(random_bytes(16))` filename with original extension. Falls back to `rename()` when `is_uploaded_file()` is false (test context).

### 4. Write `FileUploadTest`

Created 10 tests using a real temp file with a minimal JPEG SOI+APP0 header so `finfo` detects `image/jpeg` without a live upload:

```php
file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 16));
```

Injected fake `$files` arrays into the static factory methods to avoid touching `$_FILES`. All 10 tests exercise the public API surface, including 400 / 413 / 415 error paths with status-code assertions on the `HttpTermination` response.

**Finding (F-2)**: The `require()` test that catches `Error: Class not found` (before `composer dump-autoload`) surfaces a potential CI footgun: new `class/xion/*.php` files require a `composer dump-autoload` step to appear in the PSR-4 map. This is expected but worth noting for local development.

### 5. Update docs

Updated `docs/development/file-uploads.md` to add:

- `FileUpload` row in the pieces table.
- Full API reference table.
- Controller usage pattern (fluent chain).
- Destination directory setup guidance.
- Safe filename generation rationale.
- Link to this trial report in the Related section.

### 6. Run checks

```
vendor/bin/phpunit --testsuite unit   →  232 tests, 428 assertions — OK
vendor/bin/phan --no-progress-bar     →  EXIT 0
```

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `load()` with absent field | Returns `null` | Returns `null` | Pass |
| `load()` with `UPLOAD_ERR_NO_FILE` | Returns `null` | Returns `null` | Pass |
| `require()` with absent field | Throws `HttpTermination` 400 | Throws `HttpTermination` 400 | Pass |
| `require()` with `UPLOAD_ERR_INI_SIZE` | Throws `HttpTermination` 400 | Throws `HttpTermination` 400 | Pass |
| `validateSize()` within limit | Returns `$this` | Returns `$this` | Pass |
| `validateSize()` exceeds limit | Throws `HttpTermination` 413 | Throws `HttpTermination` 413 | Pass |
| `validateMime()` allowed type | Returns `$this` | Returns `$this` | Pass |
| `validateMime()` rejected type | Throws `HttpTermination` 415 | Throws `HttpTermination` 415 | Pass |
| `originalName()` / `size()` / `mimeType()` | Return constructed values | Return correct values | Pass |
| `load()` with valid upload | Returns `FileUpload` instance | Returns `FileUpload` instance | Pass |

## Friction Summary

| ID | Location | Severity | Kind | Decision |
| --- | --- | --- | --- | --- |
| F-1 | `class/xion/UploadedFile.php::moveTo()` | low | design-trade-off | `FileUpload::moveTo()` generates the filename by default; `UploadedFile` kept for backward compat |
| F-2 | local dev workflow | low | process-gap | document in onboarding: run `composer dump-autoload` after adding a new class file |
