# CLAUDE.md — NeNe Developer Notes for AI Agents

Quick-reference conventions for AI-assisted development on NeNe.
Full context is in `docs/ai/README.md` and the `docs/ai/self-review/` checklists.

---

## Pre-commit Checklist

Run this before every `git push`:

```bash
composer precommit   # format → analyze (full) → test
```

Or piecemeal:

```bash
composer format                      # auto-fix code style
composer analyze:file -- class/xion/Foo.php tests/Unit/Xion/FooTest.php   # targeted Phan (~14 s)
composer analyze                     # full Phan (~40 s) — same as CI
composer test                        # PHPUnit unit suite
```

---

## Scaffolding a New Helper (`Nene\Kit`) — the common case

Per **ADR-0014**, framework core lives in `Nene\Xion` (`class/xion/`, ~55 classes)
and the opt-in helper catalogue lives in `Nene\Kit` (`class/kit/`, ~255 classes).
**Field-trial helpers go in `Nene\Kit`:**

```bash
composer make:kit -- ClassName
```

Creates `class/kit/ClassName.php` + `tests/Unit/Kit/ClassNameTest.php` with the
PDO injection skeleton (including `use Nene\Xion\PdoConnection;`), and registers
the class in the Uncategorized section of `class/kit/INDEX.md`. Then:

1. Fill in the public API and table DDL (in the class docblock; helpers are
   self-contained and do **not** register in `SchemaDefinition`)
2. Move the Uncategorized entry in `class/kit/INDEX.md` to the right section
3. Run `composer analyze:file -- class/kit/ClassName.php tests/Unit/Kit/ClassNameTest.php`

For a genuine **framework-core** class (rare), use `composer make:xion -- ClassName`
(→ `class/xion/`, `Nene\Xion`) instead. A core class that ships sample-app schema
also adds its table to `class/xion/SchemaDefinition.php`.

---

## Completing an FT

After merging the PR, run:

```bash
composer ft:done -- FT265 ClassName "one-line description of what it does" 712
```

Updates three files in one shot:

| File | Change |
|---|---|
| `docs/todo/current.md` | Advances `FT1–FT264 are complete` → `FT1–FT265` |
| `docs/field-trials/candidates.md` | Prepends archive entry |
| `docs/roadmap.md` | Advances `FT1–FT264 complete as of …` → `FT1–FT265` |

---

## Static Analysis (Phan)

```bash
# Full run (~40 s) — same as CI:
composer analyze

# Targeted run (~14 s) — before pushing a new Xion class:
composer analyze:file -- class/xion/Foo.php tests/Unit/Xion/FooTest.php

# Multiple files:
composer analyze:file -- class/xion/Foo.php class/xion/Bar.php tests/Unit/Xion/FooTest.php
```

Suppression annotations go on the line **before** the offending line.
See `.phan/suppress-cheatsheet.md` for the full reference; quick guide:

| Error name | When |
|---|---|
| `PhanTypeMismatchArgumentNullable` | `?T` → user-defined function expecting `T` |
| `PhanTypeMismatchArgumentNullableInternal` | `?T` → PHP built-in (`strlen`, `json_decode`, …) |
| `PhanTypeArraySuspiciousNullable` | `?array` key access (even after `assertNotNull`) |
| `PhanTypeComparisonFromArray` | `fetchAll() === false` comparison |
| `PhanTypeMismatchDeclaredParamNullable` | PHPDoc `@param T` vs `?T` signature |
| `PhanTypeMismatchDeclaredReturn` | PHPDoc `@return` vs actual return type |

---

## Cross-Driver Upsert

Use `DbUpsert::run()` instead of writing driver-specific SQL by hand:

```php
use Nene\Xion\DbUpsert;

DbUpsert::run(
    $this->db(),
    table:        'presence',
    data:         ['user_id' => $userId, 'context' => $context],
    conflictCols: ['user_id', 'context'],          // SQLite ON CONFLICT clause
    updateCols:   ['context'],                     // copied from excluded row
    updateExprs:  ['seen_at' => 'CURRENT_TIMESTAMP'], // raw SQL expressions
);
```

- `updateCols` → `col = VALUES(col)` (MySQL) / `col = excluded.col` (SQLite)
- `updateExprs` → raw SQL appended to SET, e.g. `'seen_at' => 'CURRENT_TIMESTAMP'`
- Both optional; omit both for INSERT … DO NOTHING on conflict

---

## Date Boundary Tests

Off-by-one errors are the most common test bug. Always annotate literal dates
with a relative comment so the intent is obvious and reviewable:

```php
$now      = '2026-05-28';
$expiry6  = '2026-06-03'; // +6 days — within 7-day window
$expiry7  = '2026-06-04'; // +7 days — exactly at boundary (included)
$expiry8  = '2026-06-05'; // +8 days — outside window, must NOT appear
```

Rule: **one line per boundary case**, each line with its `// +N days` comment.
Never use a date that is only "near" the boundary and hope it works.

---

## Class Indexes (consult before starting — avoid duplicates)

- `class/kit/INDEX.md` — the ~255 `Nene\Kit` helper classes grouped by domain.
- `class/xion/INDEX.md` — the ~55 `Nene\Xion` framework-core classes.

**Concept-scan, not just name-scan**, before adding a helper — grep the INDEX
*descriptions* (`grep -i <keyword> class/kit/INDEX.md`) to catch conceptual
duplicates (e.g. a "terms acceptance" idea already covered by `TermConsent`).

To regenerate after adding classes:

```bash
composer kit:index    # class/kit/INDEX.md  (helpers)
composer xion:index   # class/xion/INDEX.md (core)
```

---

## PDO Injection Pattern

All Xion classes accept an optional `?PDO $db` in their constructor and fall
back to `PdoConnection::getInstance()` when it is `null`:

```php
public function __construct(private readonly ?PDO $db = null) {}

private function db(): PDO
{
    return $this->db ?? PdoConnection::getInstance();
}
```

Tests pass an in-memory SQLite PDO; production uses the singleton.

---

## Monetary Values

All amounts are stored as **integer cents** (e.g. `$1.50` → `150`).
No floats in the database, no `DECIMAL` in SQLite tests.

---

## Token Security

Never store raw tokens. Always hash with SHA-256:

```php
$token  = bin2hex(random_bytes(32));        // 64 hex chars, plaintext — return to caller
$hashed = hash('sha256', $token);           // store this
```

---

## Session State

At the end of each work session, write a brief `.claude/session-state.md`
noting what branch you are on, what is uncommitted, and what remains to do.
This lets the next session resume without re-deriving state from git.
