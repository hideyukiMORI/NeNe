# Field Trial 23 — NENE2 Pattern Survey

**Date:** 2026-05-27
**Theme:** Survey NENE2 FT80–99 findings and port all applicable patterns into NeNe documentation and code
**Source:** `/home/xi/docker/NENE2/docs/field-trials/` (FT80–FT99)
**PR:** #455

---

## What was done

A systematic review of 20 NENE2 field trials (FT80–FT99) was conducted to extract patterns applicable to NeNe. NENE2 is a separate PHP framework that runs similar field trials; its findings on security, data modeling, and API design patterns translate directly.

From the review, 23 candidate ideas were identified. All were incorporated into NeNe in this trial:

**Code fix (1):**
- `class/xion/JsonResponder.php` — added `JSON_UNESCAPED_UNICODE` so Japanese, emoji, and other non-ASCII characters appear as-is in API responses instead of `\uXXXX` escape sequences. (Source: NENE2 general pattern)

**New documentation (19 files in `docs/development/`):**

| File | Pattern | Source FT |
|---|---|---|
| `idor-prevention.md` | SQL-level ownership enforcement; 404 not 403 | FT94 |
| `sql-injection.md` | Parameterized queries; LIKE escaping; ORDER BY allowlist | FT94 |
| `unicode-validation.md` | `mb_strlen` vs `strlen`; null-byte; `grapheme_strlen` | FT94 |
| `optimistic-locking.md` | ETag/If-Match; version column; 412/428 | FT93 |
| `ledger-systems.md` | Append-only; COALESCE SUM; idempotency key | FT83 |
| `state-machines.md` | WorkflowDefinition; transitions table; allowed_next | FT87 |
| `temporal-data.md` | effective_from/effective_to; NULL open-ended; auto-close | FT80 |
| `booking-systems.md` | UNIQUE constraint; SELECT FOR UPDATE; conditional INSERT | FT79 |
| `feature-flags.md` | Global default + per-user override; Redis caching | FT98 |
| `cors-and-csrf.md` | CORS ≠ CSRF; Bearer is CSRF-immune | FT92 |
| `timezone-handling.md` | Store UTC; IANA `listIdentifiers()` validation; DST handling | FT95 |
| `invitation-tokens.md` | Random code generation; pending/claimed/revoked; expiry | FT85 |
| `waitlist-queue.md` | FIFO; derived position via COUNT; soft-status | FT84 |
| `many-to-many.md` | Join table; EXISTS filter; idempotent add; cascade delete | FT88 |
| `reactions.md` | Toggle semantics; polymorphic target_type; UNIQUE as key | FT89 |
| `ratings.md` | Upsert try-INSERT/catch; aggregated summary; distribution | FT82 |
| `session-tracking.md` | Token-based session table; heartbeat; revokeAll; rowCount | FT86 |
| `rate-limiting.md` | Redis INCR+EXPIRE; key strategies; Retry-After; Lua atomic | gap (not in NENE2) |
| `json-only-api.md` | Accept header behavior; json_encode([]) pitfall; non-ASCII | FT96 |

**Enhanced documentation (1 file):**
- `docs/development/agent-bearer-auth.md` — added "JWT-based Bearer auth" section with edge cases table: `alg:none` attack, `exp` validation, `hash_equals`, IDOR via `sub` claim (source: FT94)

---

## Findings

### F-1 — `JSON_UNESCAPED_UNICODE` was missing from JsonResponder (medium)

**Symptom:** API responses containing Japanese text or emoji returned `\uXXXX` escape sequences instead of the actual characters.

```json
// Before
{ "name": "田中太郎" }

// After
{ "name": "田中太郎" }
```

**Fix:** Added `JSON_UNESCAPED_UNICODE` to `JsonResponder::encode()`.

**Note:** `View::encodeScriptJson()` was intentionally not changed — script-context JSON embedding requires XSS-safe escaping and should keep `JSON_HEX_TAG`.

### F-2 — IANA timezone validation needs `listIdentifiers()` membership check (medium, documented)

PHP's `DateTimeZone` constructor silently accepts non-IANA abbreviations like `"EST"`. `new DateTimeZone('EST')` succeeds without exception, but `"EST"` is not in `DateTimeZone::listIdentifiers()`. Validation that only uses `try/catch` passes invalid abbreviations.

**Fix documented in `timezone-handling.md`:** Always validate with `in_array($tz, DateTimeZone::listIdentifiers(), true)`.

### F-3 — `json_encode([])` produces `[]` not `{}` — pitfall in tests (low, documented)

PHP's `json_encode([])` returns a JSON array `[]`, not an empty object `{}`. Sending `[]` as a request body to NeNe controllers that read `$this->REQUEST_JSON['field']` causes unexpected behavior or 400 errors.

**Fix documented in `json-only-api.md`:** Send `new \stdClass()` or an object with unrelated fields when testing "no body" scenarios.

### F-4 — Rate limiting is a documented gap (low, new doc)

No rate-limiting middleware exists in NeNe (or NENE2). The pattern was documented from first principles in `rate-limiting.md` using Redis INCR+EXPIRE, matching NeNe's existing Redis dependency.

---

## Results

| Artifact | Count |
|---|---|
| PHP files modified | 1 |
| Documentation files created | 19 |
| Documentation files enhanced | 1 |
| Source FTs surveyed | 20 (FT80–FT99) |
| Candidate ideas extracted | 23 |
| Ideas incorporated | 23 (100%) |

---

## Related

- Source: `/home/xi/docker/NENE2/docs/field-trials/` (FT80–FT99)
- PR: #455
- Preceding trial: FT22 (ai-agent-journey) → found doc gaps → many of these docs address those gaps
