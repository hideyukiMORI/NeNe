# Field Trial 38 — Full-Text Search (SQLite FTS5 + MySQL LIKE)

**Date:** 2026-05-27
**Theme:** `SearchQuery` helper class — LIKE escape, FTS5 sanitizer, input normalization
**Issue:** #FT38

---

## What was built

A `Nene\Func\SearchQuery` final class that provides composable, database-aware search helpers. The class has no state and no dependencies; all methods are static.

### `class/func/SearchQuery.php`

| Method | Description |
|---|---|
| `escapeLike(string $term): string` | Escapes `%`, `_`, `\` for SQL LIKE patterns |
| `likePattern(string $term, string $mode): string` | Builds a complete `%…%`, `…%`, or `%…` pattern |
| `sanitizeFts(string $term): string` | Strips double quotes and trims for safe FTS5 MATCH |
| `normalize(string $input): string` | Replaces null bytes with space, collapses whitespace, trims |

### `tests/Unit/Func/SearchQueryTest.php`

19 tests, 19 assertions covering all four methods:

- `escapeLike` — special chars (`%`, `_`, `\`), plain string, empty string
- `likePattern` — contains/starts-with/ends-with modes, special-char escaping, unknown mode fallback
- `sanitizeFts` — double-quote removal, whitespace trimming, empty string
- `normalize` — null byte replacement, whitespace collapse, tab/newline handling, empty string

### `docs/development/full-text-search.md`

Howto covering:

- SQLite FTS5: virtual table creation, INSERT/UPDATE/DELETE triggers, MATCH query with `SearchQuery::sanitizeFts()`
- BM25 rank sign explanation (negative = better)
- PDOException catch for invalid FTS5 queries → 400
- MySQL FULLTEXT (ALTER TABLE + MATCH AGAINST)
- LIKE fallback with `SearchQuery::likePattern()` and `ESCAPE '\\'`
- Input normalization pattern
- Strategy selection guide

---

## Findings

### F-1 — `normalize()` null byte handling: replace, not strip (design note)

The specification said "null byte 除去" (remove null bytes). The first implementation used `str_replace("\0", '', $input)`, which removed the null byte without inserting a space, causing `"hello\0world"` to produce `"helloworld"` rather than `"hello world"`.

The test spec showed the expected output as `'hello world'`, making it clear the null byte should be replaced with a space so that FTS and LIKE tokenization works correctly. The fix was to change the replacement character from `''` to `' '`, after which `normalize()` collapses any resulting run of spaces via the `\s+` regex.

**Fix:** `str_replace("\0", ' ', $input)` — null bytes become spaces, then `preg_replace('/\s+/u', ' ', ...)` collapses duplicates.

### F-2 — FTS5 MATCH on empty string raises PDOException (known, documented)

Calling `MATCH ''` against a SQLite FTS5 virtual table raises `PDOException: fts5: syntax error`. `sanitizeFts()` strips quotes and trims but does not guard against an all-whitespace input becoming an empty string after trim.

Callers should check for `$safeQuery === ''` before executing the MATCH query and return an empty result set or a 400. This is documented in `docs/development/full-text-search.md` section 1.3 and the exception catch pattern in section 1.5. A guard inside `sanitizeFts()` itself was rejected because the method's responsibility is sanitization, not flow control — the caller decides whether an empty term is an error or a no-op.

### F-3 — `likePattern()` unknown mode falls back silently to 'contains' (by design)

The `match` statement uses `default` to catch any unrecognised mode string. A strict alternative would throw `\ValueError`. The silent fallback was kept intentionally: callers typically pass a hardcoded mode string, and a fallback to `contains` is the least surprising behavior for an unknown value. If the project needs strict mode validation in the future, replacing `default` with a `throw new \ValueError(...)` arm is a one-line change.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) `composer test` | 241 tests, 431 assertions — OK |
| Phan `composer analyze` | 0 errors (exit 0) |
| PHP syntax | `php -l class/func/SearchQuery.php` — no errors |

---

## Related

- `class/func/SearchQuery.php` — implementation
- `tests/Unit/Func/SearchQueryTest.php` — test suite
- `docs/development/full-text-search.md` — howto
- NENE2 FT38 (searchlog), FT57 (ftslog): FTS5 virtual table, MATCH query, rank ordering, invalid query 400
