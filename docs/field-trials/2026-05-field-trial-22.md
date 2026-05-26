# Field Trial 22 — ai-agent-journey

**Date**: 2026-05-27
**Issue**: N/A (meta-trial, no feature issue)
**PRs**: #451 (fixes + report)
**Branch**: `feat/447-ft22-doc-fixes`

## Objective

Phase 6 thesis self-verification: bootstrap a fresh NeNe clone with a clean AI agent (a `general-purpose` subagent with no prior NeNe knowledge), give it the docs and the task of building a small REST service end-to-end, and record what was still confusing.

**Service built**: `bookmarks` — four public REST endpoints (`GET /bookmark/index`, `POST /bookmark/index`, `GET /bookmark/item/id_{id}`, `DELETE /bookmark/item/id_{id}`).

**Clone**: `../NeNe-FT/ft22-ai-agent-journey` (fresh clone at `40fffd5`, same commit as main at time of trial).

## Outcome

The agent successfully built and tested the bookmarks service. All tests pass:

- Unit: **198 tests, 381 assertions** (0 failures)
- HTTP: **48 tests, 362 assertions, 6 skipped** (skips are expected: bearer-auth and error-exposure env vars not set)

The service is fully functional. The agent's code was clean and idiomatic NeNe.

## Findings

### F-1 — `DataMapperBase::delete()` name collision (CRITICAL doc gap) → Issue #446

**What happened**: The agent tried to implement a soft-delete mapper method named `delete(int $id)`. `DataMapperBase` already declares `public function delete(mixed $data): void`. PHP 8.4 treats the incompatible signature override as a fatal error.

**Why it matters**: A newcomer following the NeNe pattern for soft-delete (the `is_deleted = 1` idiom) would naturally reach for `delete()` as the method name. The tutorial examples use `is_deleted` but do not show how to implement the deletion half — and make no mention that `insert()`, `update()`, `delete()`, `find()`, `findALL()`, `countById()`, `countAll()` are all reserved by the base class.

**Fix**: Added a "Reserved method names in DataMapperBase" warning section to `docs/tutorials/building-a-service.md` (this PR). Canonical alternative: `softDelete(int $id): bool`.

### F-2 — `SchemaDefinition` has no nullable column type (undocumented limitation) → Issue #448

**What happened**: The task asked for an optional `note` column (nullable text). `SchemaCompiler` compiles all columns as `NOT NULL`. The agent had to read `SchemaCompiler.php` source to discover the limitation and use empty string as the sentinel instead.

**Why it matters**: The tutorial, `SchemaDefinition.php` docblock, and `schema-migrations.md` are all silent on this. Newcomers attempting to model optional data will be confused when `INSERT` fails on `NULL`.

**Fix (partial)**: Added a "Column nullability" note to the tutorial (this PR). Long-term: add a `nullable` modifier to `SchemaCompiler` (Issue #448 deferred).

### F-3 / F-6 — `composer setup` output shows hardcoded table list → Issue #449

**What happened**: `DatabaseInstaller::install()` always outputs `Tables: users, todos` regardless of what `SchemaDefinition` actually defines. After adding the `bookmarks` table and running `composer setup`, the output still said `Tables: users, todos`, making it look like the setup missed the new table.

**Why it matters**: The agent had to run `SHOW TABLES` in MySQL directly to confirm the table existed. A human newcomer would likely spend time debugging something that actually worked.

**Fix**: Filed as Issue #449 (make table list dynamic). Not fixed in this PR — requires a code change to `DatabaseInstaller`.

### F-4 — No per-entity CRUD HTTP test example → Issue #450

**What happened**: The tutorial directs developers to "look at an existing HTTP test file". The only existing file is `tests/Http/HttpSmokeTest.php` — a general smoke test covering many endpoints, not a per-entity create/read/delete test. The `HttpRuntimeTestCase` base class has Todo-specific helpers (`createTodo`, `deleteTodosWithTitlePrefix`) that are not generalizable.

**Why it matters**: The recommended test structure — create unique data per test, clean up on teardown, test each endpoint in isolation — is not illustrated anywhere. The agent pieced it together from the base class and smoke test, but a human newcomer would struggle.

**Fix**: Filed as Issue #450 (add TodoTest.php or a canonical CRUD test example to the tutorial). Not fixed in this PR — requires a TodoTest.php to be authored.

### F-5 — Tutorial doesn't mention `docs/development/error-codes.md` must be updated → Issue #447

**What happened**: The 'Add Error Codes' section shows editing `config/error_codes.php`. A unit test (`ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable`) enforces that `docs/development/error-codes.md` stays in sync. The tutorial says nothing about this. The agent discovered the requirement only when unit tests failed.

**Fix**: Added a callout block to the tutorial (this PR). Immediate.

## What worked well

- **Routing convention** (`{action}GetRest`, `{action}PostRest`, `{action}DeleteRest`) is clearly documented and correct. No surprises.
- **`SESSION_CHECK = false` in `preAction()`** for public endpoints — clear, one-liner, worked as described.
- **Schema compilation pipeline** (`SchemaDefinition` → `composer schema:generate` → `001_schema.sql`) — worked correctly once the agent understood the flow. The `SchemaDefinition.php` docblock is helpful.
- **`TransactionManager`** — clear pattern in the tutorial, worked first try.
- **`normalizeRow()` guidance** — the explicit rationale ("PDO returns everything as strings") is excellent. Agent applied it correctly.
- **`$this->REQUEST_JSON` / `$this->request->getParam()`** — clear from tutorial + TodoController reference.
- **Error code catalog structure** (`message` + `httpStatus`) — straightforward.
- **OpenAPI YAML format** — easy to copy-paste from existing paths.
- **`composer test` / `composer test:http`** — scripts work exactly as documented.

## Files changed in this PR

| File | Change |
|---|---|
| `docs/tutorials/building-a-service.md` | Added reserved-method-names warning + column nullability note (F-1, F-2) + error-codes.md callout (F-5) |
| `docs/field-trials/2026-05-field-trial-22.md` | This report |
| `docs/field-trials/candidates.md` | Strike through ai-agent-journey candidate; add to archive trail |

## Deferred findings (filed as issues)

| Issue | Finding |
|---|---|
| #446 | F-1 `delete()` collision — **fixed in tutorial** (this PR) |
| #447 | F-5 error-codes.md sync — **fixed in tutorial** (this PR) |
| #448 | F-2 nullable column limitation — deferred (implementation needed) |
| #449 | F-3/F-6 hardcoded installer table list — deferred (code change needed) |
| #450 | F-4 no per-entity CRUD test example — deferred (TodoTest.php needed) |

## Related

- `docs/field-trials/candidates.md` — ai-agent-journey candidate
- Phase 6 thesis: `docs/milestones/`
- FT22 clone: `../NeNe-FT/ft22-ai-agent-journey`
