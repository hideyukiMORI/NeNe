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

## Scaffolding a New Xion Class

```bash
composer make:xion -- ClassName
```

Creates `class/xion/ClassName.php` + `tests/Unit/Xion/ClassNameTest.php` with
the PDO injection skeleton, **and automatically adds the class to the
Uncategorized section of `class/xion/INDEX.md`**. Then:

1. Fill in the public API and table DDL
2. Add the table to `class/xion/SchemaDefinition.php`
3. Move the Uncategorized entry in `class/xion/INDEX.md` to the right section
4. Run `composer analyze:file -- class/xion/ClassName.php tests/Unit/Xion/ClassNameTest.php`

---

## Completing an FT

**Every FT must have a report file.** The archive trail entry in `candidates.md` is a one-line index pointer, not a substitute. An FT without a report file is not finished.

```bash
# 1. Create the report file (before or after merging)
bash tools/ft-report-new.sh 265 ClassName --xion   # Xion helper → Format B (~50 lines)
bash tools/ft-report-new.sh 18  topic-name          # Exploratory trial → Format A

# 2. After the PR merges, update three tracking docs
composer ft:done -- FT265 ClassName "one-line description" 712
```

`composer ft:done` updates:

| File | Change |
|---|---|
| `docs/todo/current.md` | Advances `FT1–FT264 are complete` → `FT1–FT265` + date |
| `docs/field-trials/candidates.md` | Prepends archive entry |
| `docs/roadmap.md` | Advances `FT1–FT264 complete as of …` → `FT1–FT265` + date |

**FT76–FT264** had no report files — process gap, not an approved exception.  
**FT265 onward**: report file required, no exceptions.

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

## Xion Class Index

`class/xion/INDEX.md` — all ~230 Xion classes grouped by domain.
Consult it before starting a new Xion class to avoid duplicates.

To regenerate after adding classes:

```bash
composer xion:index   # updates descriptions, adds new classes to Uncategorized
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
