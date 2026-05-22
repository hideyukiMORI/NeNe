# Field Trial 10 — openapi-authoring (round-trip a new `Memo` entity from openapi.yaml to runtime contract test)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #346.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT9 main (all #340–#343 follow-up PRs merged: Smarty plugin wiring + smarty-plugins.md).
- Clone path: `/home/xi/github/NeNe-FT/ft10-openapi-authoring/`
- Host ports: app=8090, mysql=3317
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 50 / 50, 134 assertions |
| `composer test:http` | 23 / 23, 219 assertions, 1 expected skip |

## Goal

ADR-0003 (`ApiFailureEnvelope`), `OpenApiRuntimeContractTest`, `docs/development/error-codes.md`, and `docs/review/openapi-contract.md` are all in place. Trial 10's job is to walk a new entity through that documented workflow end-to-end (`openapi.yaml` → controller + mapper → contract test) and record the friction that surfaces.

## Service Built

- Name: `Memo` — a single-table entity with `id`, `user_id`, `body`, `created_at`, `updated_at`, `is_deleted`.
- Endpoints exercised: `POST /memo/index` (create), `GET /memo/item/id_{id}` (read).
- Files added (in trial clone only): `class/db/Memo.php`, `class/db/MemoMapper.php`, `class/controller/MemoController.php`. Schema added to `docker/mysql/init/001_schema.sql` and to the running MySQL via `mysql -e "CREATE TABLE ..."`.
- Probe artifacts stay in the clone; not committed back.

## Steps Taken (in the order they happened)

### 1. Tutorial + review checklist review

Read `docs/tutorials/building-a-service.md` § "Update OpenAPI" and `docs/review/openapi-contract.md`. The tutorial is thin (4 bullets — "add path, method, schemas, security"); the detailed rules live in the review checklist. Both point at `docs/api/openapi.yaml` as the source-of-truth example.

### 2. Model + mapper + controller (copy-paste from Todo)

Created `class/db/Memo.php` and `class/db/MemoMapper.php` modelled on `Todo` / `TodoMapper`. Wrote `MemoController` with `indexPostRest` (`POST /memo/index`, create memo) and `itemGetRest` (`GET /memo/item/id_{id}`, read memo).

First request → HTTP 500. Error log: `Call to undefined method Nene\Controller\MemoController::getLoginUserId()`.

**Finding (F-1)**: `getLoginUserId()` is defined as a *private* helper inside `TodoController` (lines 131–134), not on `ControllerBase`. Every new entity controller needs to copy `private function getLoginUserId(): int { return (int)$this->AUTH_SESSION->userId(); }`. The tutorial uses this method by example without flagging that it isn't shared. One-line promotion fix.

### 3. Same again for `normalizeRow()`

After adding the local `getLoginUserId()`, the create endpoint then 500'd on `Call to undefined method MemoController::normalizeRow()`. `normalizeRow()` is *also* a TodoController private helper, but in this case the per-entity nature is correct (each entity decides its own JSON shape — `is_completed` vs `body`, etc.). The friction is that this *pattern* (always normalise before returning) is not documented.

**Finding (F-2)**: Document the "normalize row before returning" pattern in `docs/tutorials/building-a-service.md` § "Add a REST Endpoint" or in `docs/review/rest-controller.md`. Without it, naive contributors return raw DB rows and ship loose types (string IDs, `"0"` booleans).

### 4. Schema 3-way duplication fires

Adding the `memos` table cleanly required edits in three places:

- `docker/mysql/init/001_schema.sql`
- `class/xion/DatabaseInstaller.php` MySQL block
- `class/xion/DatabaseInstaller.php` SQLite block

The trial shortcut was to update `001_schema.sql` and run `mysql -e "CREATE TABLE ..."` against the live container; the `DatabaseInstaller` duplicates were not touched. A real PR shipping `memos` to the framework would have to edit all three.

This is FT6 F-2 firing under load. The 2026-05 follow-ups file says re-evaluate "when a future trial actually trips on the drift". FT10 just tripped on it.

**Finding (F-3)**: Escalate FT6 F-2 from deferred to a real consolidation Issue (or, more likely, an ADR — the long-term answer is single-source schema generation). This is the ADR-class line item that has been waiting for a real workflow to validate the need.

### 5. OpenAPI authoring volume

Writing `openapi.yaml` entries for the two new endpoints requires roughly:

- `paths./memo/index.post` — ~70 lines (request body, success response, 4 failure responses + per-response examples).
- `paths./memo/item/id_{id}.get` — ~50 lines.
- `components.parameters.MemoId`, `components.schemas.MemoSuccessEnvelope`, `components.schemas.MemoCreateRequest`, `components.responses.MemoNotFound` — additional sections.

The bulk of the per-operation yaml is **re-inlined failure envelopes** for SESSION-CLOSED (401) and CSRF-TOKEN-INVALID (403). Both appear on every state-changing operation. Only `#/components/responses/MethodNotAllowed` is shared. The pattern of `description: ... failure ... → schema: ApiFailureEnvelope → examples: errorCode + errorMessage` repeats word-for-word.

**Finding (F-4)**: Extract `SessionClosed` and `CsrfTokenInvalid` as reusable `#/components/responses/` entries. New operations then `$ref` them instead of inlining ~15 lines of yaml each.

### 6. Error code 3-way sync

A single new code (`MEMO-NOT-FOUND`) needs three edits to stay in sync:

- `config/error_codes.php` (runtime, source-of-truth for `httpStatus`).
- `docs/development/error-codes.md` (markdown table).
- `docs/api/openapi.yaml` (in `examples:` blocks per-operation).

No test / lint enforces the first two. The review checklist mentions both but does not fail a PR that updates only one.

**Finding (F-5)**: Unit test that walks `config/error_codes.php` keys and asserts each appears as a row in `docs/development/error-codes.md`. The openapi-examples drift is harder to catch (per-operation, optional) so leave that to review.

### 7. Tooling

There is no scaffold tool (`tools/nene-new-resource.sh <name>`) to create the four files from a template. Every entity is manual copy-paste from Todo. Not a blocker — the codebase is small enough — but worth noting.

**Finding (F-6)**: Defer. A scaffold tool would halve bootstrap time; not strictly needed.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| New entity controller compiles with `$this->getLoginUserId()` (from tutorial) | works | 500 — method is per-controller, not on ControllerBase | Blocked |
| New entity controller can `$this->normalizeRow($row)` (from tutorial) | works | 500 — method is per-entity (correct) but undocumented | Blocked |
| Single-table CRUD endpoints work after copy-paste from Todo | yes | yes, after local `getLoginUserId` + `normalizeRow` patches | Pass |
| `docker/mysql/init/001_schema.sql` is the only schema location to touch | yes | no — 3 places (FT6 F-2) | Blocked |
| OpenAPI yaml authoring for one new endpoint < 30 lines | ideal | ~70 lines per state-changing op (mostly re-inlined failure responses) | Partial |
| New error codes catch typos / drift via existing tooling | ideal | no test catches `config/error_codes.php` vs `error-codes.md` drift | Partial |

## Friction Summary

| ID  | Location                                      | Severity | Kind         | Decision        |
| --- | --------------------------------------------- | -------- | ------------ | --------------- |
| F-1 | `class/xion/ControllerBase.php`               | medium   | feature-gap  | fix-in-framework |
| F-2 | tutorial / rest-controller review             | low      | docs-gap     | document        |
| F-3 | (cross-ref FT6 F-2)                          | medium   | ADR-class    | escalate        |
| F-4 | `docs/api/openapi.yaml` (components)          | medium   | feature-gap  | fix-in-framework |
| F-5 | (no test)                                    | low      | feature-gap  | fix-in-framework |
| F-6 | (no tool)                                    | low      | feature-gap  | defer           |

## Recommendations

### Immediate (small framework change)

1. **F-1 — Promote `getLoginUserId()` to ControllerBase.** Move the method from `TodoController` (lines 131–134) to `ControllerBase`. Drop the private copy in TodoController. New entity controllers can then call `$this->getLoginUserId()` directly. Two-file diff.
2. **F-4 — Add reusable `SessionClosed` / `CsrfTokenInvalid` responses in `openapi.yaml`.** Refactor the existing TodoController operations to `$ref` them. New operations then add ~30 lines instead of ~70.
3. **F-5 — Add an error-code sync test.** Walk every key in `config/error_codes.php` and assert it appears in `docs/development/error-codes.md`. Run as part of `composer test`.

### Immediate (documentation only)

1. **F-2 — Document the "normalize before returning" pattern.** Add a short subsection to `docs/tutorials/building-a-service.md` § "Add a REST Endpoint" (or `docs/review/rest-controller.md`) showing why and how each entity's controller defines its own `normalizeRow()`. Include the typical column casts (`int`, `string`, `bool`).

### Suggested (ADR)

1. **F-3 — Escalate FT6 F-2.** Schema 3-way duplication fired under real workflow load. Time for the ADR. The likely shape is "single PHP-side schema description that emits both MySQL DDL and SQLite DDL", but the actual decision is out of scope for this trial.

### Trade-offs

None for FT10's other findings. The Memo round-trip succeeded; everything blocked was a tractable feature/docs gap rather than a design conflict.

## Aftermath

- Probe files (`class/db/Memo*.php`, `class/controller/MemoController.php`, schema edits) stay in the clone; not committed back.
- One Issue per actionable F-N (#347 onwards). F-6 deferred to `docs/field-trials/follow-ups.md` only if it surfaces again.
- All Issues closed by merged PR before FT11 (schema consolidation, the ADR-class candidate flagged by F-3) starts.
