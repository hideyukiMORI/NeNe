# Refresh Token

`Nene\Xion\RefreshToken` manages long-lived refresh tokens for JWT access token rotation. Pair with `JwtCodec` for a complete access + refresh token flow.

## Token lifecycle

```
Login   → issue()  → rawToken (client keeps this)
Refresh → rotate() → revokes old, issues new rawToken
Logout  → revoke() → marks token revoked (always 204 — no oracle)
```

## Security properties

| Property | Detail |
|----------|--------|
| Storage | SHA-256 hash only. Raw token returned once at issue, never stored. |
| Entropy | `bin2hex(random_bytes(32))` — 256-bit, 64-char hex |
| Replay detection | `rotate()` on a revoked token triggers `revokeAll()` for that user |
| Logout oracle | `revoke()` always succeeds silently — callers must return 204 unconditionally |

## Schema

```sql
CREATE TABLE refresh_tokens (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id    VARCHAR(255) NOT NULL,
    token_hash VARCHAR(64)  NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    revoked_at DATETIME     DEFAULT NULL,   -- NULL = active
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_refresh_tokens_user ON refresh_tokens (user_id);
```

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `issue(userId, ttlSeconds)` | `string` | Generate and store token. Returns raw token (show once). |
| `rotate(rawToken)` | `{rawToken, userId}\|null` | Rotate: verify, revoke old, issue new. Null on any failure. |
| `revoke(rawToken)` | `void` | Revoke a specific token. Noop for unknown tokens. |
| `revokeAll(userId)` | `void` | Revoke all active tokens for a user. |

## Basic usage

```php
use Nene\Xion\JwtCodec;
use Nene\Xion\RefreshToken;

$codec   = new JwtCodec((string)getenv('NENE_JWT_SECRET'), defaultTtl: 300); // 5-min access tokens
$refresh = new RefreshToken();

// ── Login ──────────────────────────────────────────────────────────────────
$accessToken  = $codec->issue([
    'sub' => $userId,
    'jti' => bin2hex(random_bytes(8)), // unique per token
]);
$refreshToken = $refresh->issue($userId, 7 * 86400); // 7-day refresh token

echo json_encode([
    'access_token'  => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_in'    => 300,
]);

// ── Token refresh ──────────────────────────────────────────────────────────
$incoming = $requestBody['refresh_token'] ?? '';
$result   = $refresh->rotate($incoming);
if ($result === null) {
    http_response_code(401);
    echo json_encode(['error' => 'UNAUTHORIZED']);
    exit;
}
['rawToken' => $newRefresh, 'userId' => $userId] = $result;
$newAccess = $codec->issue(['sub' => $userId, 'jti' => bin2hex(random_bytes(8))]);

echo json_encode([
    'access_token'  => $newAccess,
    'refresh_token' => $newRefresh,
    'expires_in'    => 300,
]);

// ── Logout (always 204 — do not reveal token state) ───────────────────────
$incoming = $requestBody['refresh_token'] ?? '';
$refresh->revoke($incoming);
http_response_code(204);
exit;
```

## Replay attack response

When `rotate()` receives a **revoked** token, it assumes the token was stolen:

1. `revokeAll()` is called for that user — all active refresh tokens are invalidated.
2. `null` is returned — the caller returns 401 and the user must re-authenticate.

The attacker's stolen (already-rotated) token produces no new token. The legitimate user discovers the compromise on their next refresh attempt and is forced to log in again.

## Access token + refresh token split

| Token | Lifetime | Stateful? | Immediate revocation? |
|-------|----------|-----------|----------------------|
| Access (JWT) | Short (5 min) | No — stateless | No — expires naturally |
| Refresh | Long (7 days) | Yes — stored as hash | Yes — `revoke()` / `revokeAll()` |

Short-lived access tokens limit the damage window from a leaked token. Long-lived refresh tokens keep users logged in without asking for credentials repeatedly.

## `jti` claim for access tokens

Issue access tokens with a `jti` (JWT ID) to ensure uniqueness, even if two tokens are issued within the same second:

```php
$codec->issue([
    'sub' => $userId,
    'jti' => bin2hex(random_bytes(8)),
]);
```

`jti` also serves as the anchor for a future token blocklist if immediate access token revocation becomes necessary.
