# Invitation Token

`Nene\Kit\InvitationToken` provides a token-based user invitation system: existing users can invite new users by email with a time-limited, high-entropy token.

## Lifecycle

```
pending ──accept()──► accepted
pending ──cancel()──► cancelled
```

Once a token leaves `pending`, it is dead. No re-entry.

## Schema

```sql
CREATE TABLE invitations (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    inviter_id   VARCHAR(255) NOT NULL,
    email        VARCHAR(255) NOT NULL,
    token        VARCHAR(64)  NOT NULL UNIQUE,
    status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
    expires_at   DATETIME     NOT NULL,
    accepted_at  DATETIME     DEFAULT NULL,
    cancelled_at DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_invitations_token ON invitations (token);
```

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `create(inviterId, email, ttlSeconds)` | `{token, id}` | Create invitation. Token is the 64-char hex to send by email. |
| `accept(token)` | `array\|null` | Accept. Null = expired, already consumed, or not found. |
| `cancel(token, inviterId)` | `bool` | Cancel. Owner-enforced. False = wrong owner, not pending, expired. |
| `find(token)` | `array\|null` | Look up an invitation by token. |

## Basic usage

```php
use Nene\Kit\InvitationToken;

$inv = new InvitationToken();

// Inviter creates an invitation
['token' => $token] = $inv->create('user:1', 'alice@example.com', 7 * 86400);
// Email $token to alice@example.com as part of the invite link.

// Alice clicks the link (token from URL parameter)
$result = $inv->accept($token);
if ($result === null) {
    // Determine: expired → 410, already consumed → 409, not found → 404
    http_response_code(410); exit;
}
// $result['inviter_id'] and $result['email'] are available
// Create the new user account here.

// Inviter cancels before acceptance
if (!$inv->cancel($token, 'user:1')) {
    // Not pending, expired, wrong inviter, or not found
}
```

## Expiry checked before status

```php
// ✅ Correct order (implemented by InvitationToken::accept())
if (expired) → null  // caller: 410 Gone
if (!pending) → null // caller: 409 Conflict

// ❌ Wrong order
if (!pending) → null // returns 409 for an expired pending token — incorrect
```

An expired pending token should signal "too late" (410), not "already consumed" (409). Checking expiry first ensures the right HTTP status.

## Owner enforcement on cancel

`cancel()` returns `false` for wrong-owner access — no 403 distinction is exposed at the helper layer. Callers should return 403 when they know the inviter exists but the actor is not the owner:

```php
$item = $inv->find($token);
if ($item === null) {
    http_response_code(404); exit;
}
if (!$inv->cancel($token, $actorId)) {
    http_response_code(403); exit; // exists but not your invite
}
http_response_code(204);
```

## Status constants

```php
InvitationToken::STATUS_PENDING   // 'pending'
InvitationToken::STATUS_ACCEPTED  // 'accepted'
InvitationToken::STATUS_CANCELLED // 'cancelled'
```

## Token entropy

`bin2hex(random_bytes(32))` produces a 64-character hex string with 256 bits of entropy. Brute-forcing this is computationally infeasible. The `UNIQUE` constraint on `token` prevents collision at the DB layer.
