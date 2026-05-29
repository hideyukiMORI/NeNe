# Field Trial 305 — Annotation

**Date**: 2026-05-29
**Branch**: `feat/ft305-annotation`
**Baseline**: post FT304 merge

## Goal

Add `Nene\Kit\Annotation` — per-user text highlights and notes anchored to a character range within a document (Kindle / Hypothes.is-style). Distinct from `EntityComment` (FT214, flat entity comments) and `CommentThread` (FT69): this anchors to a `[start, end)` text range.

## What was built

### `Nene\Kit\Annotation` (`class/kit/Annotation.php`)

| Method | Description |
| --- | --- |
| `add(userId, document, start, end, quote='', note=''): int` | Highlight a range. |
| `get(id) / forDocument(document) / forUser(userId, document)` | Read (ordered by start). |
| `countFor(document) / updateNote(id, note) / remove(id)` | Count / edit / delete. |

Key design points:

- **Half-open `[start, end)` range** with `end > start` and `start >= 0` validated; stores the highlighted `quote` + optional `note`.
- `forDocument`/`forUser` ordered by start offset then id (stable reading order).
- `updateNote` returns whether a row changed.

### Tests (`tests/Unit/Kit/AnnotationTest.php`)

13 unit tests (23 assertions): add/get, missing null, forDocument ordered-by-start, forUser scoping, countFor, updateNote (+ missing false), remove (+ missing no-op), document separation, zero-start allowed, validation (end<=start, negative start, empty document).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
