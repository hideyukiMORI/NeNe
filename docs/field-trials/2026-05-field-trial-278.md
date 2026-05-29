# Field Trial 278 — TermGlossary

**Date**: 2026-05-29
**Branch**: `feat/ft278-term-glossary`
**Baseline**: post FT277 merge

## Goal

Add `Nene\Xion\TermGlossary` — a DB-backed glossary of domain terms and definitions (help pages, tooltips, "define this acronym" lookups), with optional categories.

## What was built

### `Nene\Xion\TermGlossary` (`class/xion/TermGlossary.php`)

| Method | Description |
| --- | --- |
| `define(term, definition, category='')` | Upsert by normalised slug. |
| `get(term) / has(term)` | Case-insensitive lookup. |
| `search(query)` | Substring match on term or definition. |
| `byCategory(cat) / categories()` | Filter / distinct list. |
| `all() / count() / remove(term)` | List / count / delete. |

Key design points:

- **Slug-keyed**: `mb_strtolower(trim(term))` is the unique key, so `API`/`api`/` Api ` collapse to one entry.
- **Safe search**: query lowercased and `LIKE` wildcards (`% _ \`) escaped, so `search('%')` matches literally rather than everything.
- **Cross-driver upsert**; **PDO injection**.

### Tests (`tests/Unit/Xion/TermGlossaryTest.php`)

14 unit tests (24 assertions): define/get, case-insensitive get, idempotent-by-slug redefine, has, search (term/definition/none), empty-query empty, **literal wildcard (`search('%')` → none)**, byCategory, distinct sorted categories (excludes blank), all term-order, remove (+ missing no-op), validation (empty term, empty definition).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
