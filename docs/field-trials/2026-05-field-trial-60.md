# Field Trial 60 — Content Draft Lifecycle

**Date**: 2026-05-27
**Branch**: `feat/ft60-content-draft`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT142 (draftlog)

## Goal

Establish a content draft/publish/archive lifecycle pattern for NeNe. Three states: `draft → published → archived`. Reverse transitions forbidden.

## What was built

### `Nene\Xion\ContentDraft` (`class/xion/ContentDraft.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `create(authorId, title, body)` | `int` | Create draft. Returns row ID. |
| `update(contentId, authorId, title, body)` | `bool` | Edit draft. Author + draft status guard. |
| `publish(contentId, authorId)` | `bool` | draft → published. |
| `archive(contentId, authorId)` | `bool` | published → archived. |
| `find(contentId, viewerId)` | `array\|null` | Get item. Draft/archived hidden from non-authors. |
| `listPublished()` | `array[]` | Published only, `ORDER BY published_at DESC, id DESC`. |

Key design points:

- **Transition guards via SQL**: the WHERE clause includes the required `status` — no separate fetch. If the row count is 0, the guard failed.
- **Reverse transitions blocked**: `publish()` requires `status = 'draft'`; `archive()` requires `status = 'published'`; `update()` requires `status = 'draft'`.
- **Author enforcement**: all mutating methods include `author_id = ?` in the WHERE clause.
- **404 not 403 for hidden content**: `find()` returns `null` for non-author access to drafts/archived — no disclosure that the content exists.
- **Stable sort**: `ORDER BY published_at DESC, id DESC` for deterministic order when two items are published in the same second. Ported from NENE2 FT142 bug-fix learning.
- **Status constants**: `ContentDraft::STATUS_DRAFT`, `STATUS_PUBLISHED`, `STATUS_ARCHIVED`.

### Tests (`tests/Unit/Xion/ContentDraftTest.php`)

24 unit tests covering:

- create: returns ID, default status is draft, author stored
- update: success, title changed, wrong author (false), published content (false)
- publish: success, status changes, published_at set, wrong author (false), already published (false)
- archive: success, status changes, on draft (false), wrong author (false)
- find: published visible to all, draft hidden from non-author, draft visible to author, archived hidden from non-author, not found
- listPublished: only published, newest first, excludes drafts + archived

### Howto (`docs/development/content-draft.md`)

State transition diagram, schema, API table, basic usage, 404 vs 403 rationale, stable sort explanation, status constants.

## Findings

### F-1 — Stable sort: `ORDER BY published_at DESC, id DESC` (ported from NENE2 FT142)

NENE2 FT142 discovered this bug in-trial: `ORDER BY published_at DESC` alone gives indeterminate order for same-second publishes. Applied proactively in NeNe.

### F-2 — No other framework findings

All 24 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
