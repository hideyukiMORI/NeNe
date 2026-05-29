# API Key Management

`Nene\Kit\ApiKey` provides API key generation, hashed storage, scope-based authorization, revocation, and rotation.

## Key design

| Property | Detail |
|----------|--------|
| Format | `nk_{base64url(32 random bytes)}` — type-prefixed, 256-bit entropy |
| Storage | SHA-256 hash only. The raw key is returned once at creation and never stored. |
| Lookup | 16-character prefix stored in `prefix` column (indexed) → O(1) lookup before hash comparison |
| Hash comparison | `hash_equals()` — timing-safe, prevents prefix oracle attacks |

## Schema

```sql
CREATE TABLE api_keys (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    prefix     VARCHAR(16)  NOT NULL,
    key_hash   VARCHAR(64)  NOT NULL UNIQUE,
    owner_id   VARCHAR(255) NOT NULL,
    scope      VARCHAR(32)  NOT NULL DEFAULT 'read',
    label      VARCHAR(255) NOT NULL DEFAULT '',
    expires_at DATETIME     DEFAULT NULL,   -- NULL = no expiry
    revoked_at DATETIME     DEFAULT NULL,   -- NULL = active
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_api_keys_prefix ON api_keys (prefix);
```

## Scope hierarchy

`admin` ⊃ `write` ⊃ `read`

An `admin` key satisfies any scope requirement. A `read` key is rejected by endpoints requiring `write` or `admin`.

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `create(ownerId, scope, label, expiresIn)` | `{rawKey, id}` | Create a key. Return the raw key once — never store it. |
| `authenticate(rawKey, requiredScope)` | `array\|null` | Verify and scope-check. Returns `null` on any failure. |
| `list(ownerId)` | `array[]` | Active keys for the owner. `key_hash` never included. |
| `revoke(keyId, ownerId)` | `bool` | Mark key revoked. Owner-enforced. |
| `rotate(oldKeyId, ownerId)` | `{rawKey, id}\|null` | Create new + revoke old (create-first to avoid lockout). |

## Basic usage

```php
use Nene\Kit\ApiKey;

$manager = new ApiKey();

// Create a key
['rawKey' => $raw, 'id' => $id] = $manager->create(
    'user:42',
    scope: 'write',
    label: 'GitHub Actions CI',
);
// Return $raw to the client. Never log or store the raw key.

// Authenticate incoming requests
$incomingKey = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$incomingKey = ltrim($incomingKey, 'Bearer ');

$key = $manager->authenticate($incomingKey, requiredScope: 'write');
if ($key === null) {
    http_response_code(401);
    echo json_encode(['error' => 'UNAUTHORIZED']);
    exit;
}
// $key['owner_id'], $key['scope'], $key['label'] are available

// Revoke
$manager->revoke($id, ownerId: 'user:42');
```

## Rotation pattern

Rotate creates a new key **before** revoking the old one. If creation fails, the old key remains active — no lockout risk.

```php
$result = $manager->rotate($oldKeyId, ownerId: 'user:42');
if ($result === null) {
    // Key not found or wrong owner
}
['rawKey' => $newRaw, 'id' => $newId] = $result;
// Send $newRaw to the client. Old key is now revoked.
```

## Expiring keys

Pass `expiresIn` (seconds) to set an expiry. Expired keys are rejected by `authenticate()` without requiring an explicit revoke.

```php
// 90-day expiry
$manager->create('user:42', scope: 'read', expiresIn: 90 * 86400);
```

## Security notes

- **SHA-256 without HMAC is acceptable** for API keys because 256-bit random values cannot be brute-forced regardless. HMAC would provide marginal defense-in-depth at added operational complexity.
- **Unified 401** for all `authenticate()` failures (invalid, expired, revoked, wrong scope) — prevents state disclosure.
- **`key_hash` never exposed** in `authenticate()` or `list()` return values.
- **Ownership check on revoke/rotate** — returns `false`/`null` for wrong owner (not 403, to avoid disclosing key existence to non-owners).
