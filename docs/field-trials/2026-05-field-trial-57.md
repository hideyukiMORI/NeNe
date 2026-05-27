# Field Trial 57 — JWT Refresh Token Rotation

**Date**: 2026-05-27
**Branch**: `feat/ft57-jwt-refresh-token`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT113 (refreshlog)

## Goal

Establish a JWT refresh token rotation pattern for NeNe. Pair with `JwtCodec` (FT30): access tokens are short-lived JWTs (stateless), refresh tokens are long-lived random values stored as SHA-256 hashes.

## What was built

### `Nene\Xion\RefreshToken` (`class/xion/RefreshToken.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `issue(userId, ttlSeconds=604800)` | `string` | Generate + store. Returns raw token (once). |
| `rotate(rawToken)` | `{rawToken, userId}\|null` | Verify, revoke old, issue new. Null on failure. |
| `revoke(rawToken)` | `void` | Revoke one token. Noop for unknown. |
| `revokeAll(userId)` | `void` | Revoke all active tokens for a user. |

Key design points:

- **Hash storage**: `bin2hex(random_bytes(32))` → 256-bit raw token; SHA-256 hash stored in DB. Raw value never persisted.
- **Rotation**: `rotate()` verifies, revokes the old row, then calls `issue()` for a new token — one atomic logical operation.
- **Replay attack detection**: if `rotate()` receives a *revoked* token (token was used before), `revokeAll()` runs for that user and `null` is returned. Attacker gains nothing; legitimate user is forced to re-login.
- **Logout oracle prevention**: `revoke()` is always silent (no error on unknown tokens). Callers must return 204 unconditionally — never 404 or 401 on logout.
- **`jti` guidance**: howto documents using `jti` in access tokens for uniqueness and future blocklist support.

### Tests (`tests/Unit/Xion/RefreshTokenTest.php`)

16 unit tests covering:

- issue: 64-char hex, hash stored not raw, int userId, distinct tokens
- rotate: valid token → new token + userId, old token revoked, unknown token, expired token
- rotate replay: revoked token triggers revokeAll + other user's tokens unaffected
- rotated new token is usable (chain)
- revoke: active token, unknown token (noop), already-revoked (noop)
- revokeAll: invalidates all tokens for user, does not affect other users
- hash: returns SHA-256

### Howto (`docs/development/refresh-token.md`)

Security properties table, schema, API table, basic usage (login / refresh / logout flows), replay attack response, access + refresh token split comparison, `jti` guidance.

## Findings

### F-1 — Hash storage and replay detection ported from NENE2 FT113

Both patterns applied proactively:

1. Store SHA-256 hash, never raw token (NENE2 FT113 finding 1).
2. Replay detection on revoked token → `revokeAll()` (NENE2 FT113 finding 3).

No NeNe-specific friction discovered — the class integrates cleanly with the existing `JwtCodec` pattern.

### F-2 — No other framework findings

All 16 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
