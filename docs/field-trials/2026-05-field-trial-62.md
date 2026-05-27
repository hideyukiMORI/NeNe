# Field Trial 62 — Invitation Token

**Date**: 2026-05-27
**Branch**: `feat/ft62-invitation-token`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT124 (invitelog)

## Goal

Establish a token-based user invitation pattern for NeNe: high-entropy tokens, expiry enforcement, `pending → accepted/cancelled` lifecycle, owner-only cancellation.

## What was built

### `Nene\Xion\InvitationToken` (`class/xion/InvitationToken.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `create(inviterId, email, ttlSeconds=604800)` | `{token, id}` | Create invitation. 64-char hex token. |
| `accept(token)` | `{id,inviter_id,email,created_at}\|null` | Accept. Expiry checked before status. |
| `cancel(token, inviterId)` | `bool` | Cancel. Owner-enforced. |
| `find(token)` | `array\|null` | Look up by token. |

Key design points:

- **Token entropy**: `bin2hex(random_bytes(32))` — 256-bit, 64-char hex. `UNIQUE` constraint prevents DB-level collision.
- **Expiry before status in `accept()`**: expired pending token returns `null` (caller: 410), not 409. Checking status first would incorrectly imply the token was consumed.
- **No re-entry**: `accept()` and `cancel()` require `status = 'pending'` — once consumed, dead.
- **Owner enforcement in `cancel()`**: WHERE includes `inviter_id = ?` — wrong owner returns `false` silently. Callers map this to 403.
- **Status constants**: `STATUS_PENDING`, `STATUS_ACCEPTED`, `STATUS_CANCELLED`.
- **Column-order safety in INSERT**: named bind parameters (not positional `?`) prevent the bug found in NENE2 FT124 (positional params swapped `token` and `status`).

### Tests (`tests/Unit/Xion/InvitationTokenTest.php`)

18 unit tests covering:

- create: returns token + id, 64-char hex, pending status, distinct tokens
- accept: valid token returns inviter/email, status becomes accepted, unknown token, already accepted, cancelled, expired, expiry-before-status ordering
- cancel: by inviter succeeds, status becomes cancelled, wrong inviter, already accepted, expired, unknown token
- find: returns invitation, unknown token returns null

### Howto (`docs/development/invitation-token.md`)

Lifecycle diagram, schema, API table, basic usage, expiry-before-status explanation, owner enforcement pattern, status constants, token entropy note.

## Findings

### F-1 — Expiry-before-status ordering (ported from NENE2 FT124)

NENE2 FT124 documented the correct check order for `accept()`: expiry → status. Applied proactively. Verified by `testAcceptExpiryCheckedBeforeStatus` test.

### F-2 — Named bind parameters prevent column-order bugs

NENE2 FT124 found a positional INSERT bug (`token` and `status` columns swapped) discovered by the first test. NeNe uses named bind parameters (`:tok`, `:status`) throughout — no positional `?` in multi-column INSERTs — making this class of bug impossible.

### F-3 — No other findings

All 18 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
