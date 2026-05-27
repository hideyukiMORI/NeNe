# Field Trial 56 — API Key Management

**Date**: 2026-05-27
**Branch**: `feat/ft56-api-key-management`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT117 (apikeylog)

## Goal

Establish an API key management pattern for NeNe: key generation with type prefix, SHA-256 hashed storage, scope-based authorization, revocation, and rotation.

## What was built

### `Nene\Xion\ApiKey` (`class/xion/ApiKey.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `create(ownerId, scope, label, expiresIn)` | `{rawKey, id}` | Generate and store a key. Raw key returned once. |
| `authenticate(rawKey, requiredScope)` | `array\|null` | Verify key + scope. Null for any failure (unified 401 policy). |
| `list(ownerId)` | `array[]` | Active keys for owner. `key_hash` excluded. |
| `revoke(keyId, ownerId)` | `bool` | Mark revoked. Owner-enforced. |
| `rotate(oldKeyId, ownerId)` | `{rawKey, id}\|null` | Create-first + revoke-after (no lockout risk). |

Key design points:

- **Format**: `nk_{base64url(32 random bytes)}` — 256-bit entropy, type-prefixed for log searchability.
- **Storage**: SHA-256 hash only. Raw key returned once at creation, never stored.
- **Prefix-based lookup**: first 16 chars stored in indexed `prefix` column → O(1) DB lookup before `hash_equals()` comparison. Avoids the FT117 vulnerability V1 (full table scan when prefix = type prefix only).
- **Scope hierarchy**: `admin ⊃ write ⊃ read`, encoded in `SCOPE_LEVELS` map; `scopeAllows()` is a simple integer comparison.
- **Rotation safety**: create-first, revoke-after — worst case is two active keys briefly; no lockout path.
- **Unified null return**: all `authenticate()` failures (invalid, expired, revoked, wrong scope) return `null` — no oracle for key state.
- **`key_hash` never returned**: omitted from `authenticate()` and `list()` results.

### Tests (`tests/Unit/Xion/ApiKeyTest.php`)

27 unit tests covering:

- create: returns rawKey + id, key starts with `nk_`, explicit scope/label/expiry, invalid scope throws
- authenticate: valid key, empty key, invalid key, revoked key, expired key, key_hash not exposed
- scope enforcement: read→read (pass), read→write (fail), write→read (pass), admin→all (pass), write→admin (fail)
- list: owner isolation, excludes revoked, no key_hash
- revoke: correct owner, wrong owner, already revoked
- rotate: returns new key, revokes old key, new key inherits scope, wrong owner

### Howto (`docs/development/api-key.md`)

Key design table, schema, scope hierarchy, API table, basic usage, rotation pattern, expiring keys, security notes.

## Findings

### F-1 — Prefix length: type-only prefix causes full table scan (ported from NENE2 FT117 V1)

**Symptom** (NENE2): `extractPrefix()` returned `nk` (the type prefix) for all keys. `WHERE prefix = 'nk'` scanned every row on every authentication.

**Fix applied proactively**: `extractPrefix()` returns `substr($rawKey, 0, 16)` — includes `nk_` plus 13 chars of random material, giving ≈78 bits of differentiation (effectively unique per key).

### F-2 — No other framework findings

All 27 tests pass; CS Fixer and Phan clean. No changes to existing NeNe core classes.

## Decision

Merge as-is. F-1 ported from NENE2 as a proactive fix. No follow-up Issues raised.
