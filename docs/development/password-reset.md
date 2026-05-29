# Password Reset Flow

How to implement a secure token-based password reset in NeNe.

## Framework helper: `PasswordResetToken`

| Method | Description |
|--------|-------------|
| `PasswordResetToken::generate()` | 64-char hex random token (send to user) |
| `PasswordResetToken::hash(string)` | SHA-256 hash (store in DB) |
| `PasswordResetToken::verify(string, string)` | Constant-time raw vs stored hash check |
| `PasswordResetToken::isExpired(?string, ?string)` | Expiry check |
| `PasswordResetToken::expiresAt(int, ?string)` | Calculate future expiry datetime |

## Security design

- **Store only the hash**: `PasswordResetToken::hash($raw)` goes to the DB; `$raw` goes to the user via email.
- **User enumeration prevention**: `POST /password-reset` always returns 202 regardless of whether the email is registered.
- **One-time use**: Set `used_at = NOW()` on completion. Reject already-used tokens with 409.
- **Expiry**: Reject expired tokens with 410 Gone (not 404 — the token existed but is no longer valid).
- **Old token invalidation**: On new reset request, invalidate previous unused tokens for the same user.

## Schema example

```php
'password_resets' => [
    'columns' => [
        'id'          => ['type' => 'pk-bigint'],
        'created_at'  => ['type' => 'datetime-now'],
        'updated_at'  => ['type' => 'datetime-touch'],
        'user_id'     => ['type' => 'bigint'],
        'token_hash'  => ['type' => 'varchar:64'],    // SHA-256 of raw token
        'expires_at'  => ['type' => 'varchar:19'],
        'used_at'     => ['type' => 'varchar:19', 'nullable' => true],
    ],
    'unique' => [
        'password_resets_token_hash_unique' => ['token_hash'],
    ],
],
```

## Flow

### 1. Request reset

```php
// POST /password-reset
public function indexPostRest(): array
{
    $email = $this->body['email'] ?? '';
    $user  = $this->userMapper->findByEmail($email);

    if ($user !== false) {
        // Invalidate previous unused tokens
        $this->resetMapper->invalidateByUserId($user->id);
        // Generate new token
        $raw     = PasswordResetToken::generate();
        $expires = PasswordResetToken::expiresAt(60);
        $this->resetMapper->create($user->id, PasswordResetToken::hash($raw), $expires);
        // Send email with $raw (URL: /password-reset/{$raw})
        $this->mailer->send($email, 'Reset your password', "Link: /password-reset/{$raw}");
    }
    // Always return 202 — prevents user enumeration
    return $this->API_RESPONSE->success(['message' => 'If the email is registered, a reset link has been sent.']);
}
```

### 2. Complete reset

```php
// POST /password-reset/{token}
public function completePostRest(): array
{
    $rawToken = $this->urlParam('token');
    $row      = $this->resetMapper->findByHash(PasswordResetToken::hash($rawToken));

    if ($row === false) {
        return $this->API_RESPONSE->failure('NOT-FOUND');
    }
    if (PasswordResetToken::isExpired($row->expires_at)) {
        return $this->API_RESPONSE->failure('TOKEN-EXPIRED');   // 410
    }
    if ($row->used_at !== null) {
        return $this->API_RESPONSE->failure('TOKEN-ALREADY-USED'); // 409
    }

    $newPassword = $this->body['password'] ?? '';
    $this->userMapper->updatePassword($row->user_id, password_hash($newPassword, PASSWORD_BCRYPT));
    $this->resetMapper->markUsed($row->id);

    return $this->API_RESPONSE->success(['message' => 'Password updated successfully.']);
}
```

## Error codes to add in `config/error_codes.php`

```php
'TOKEN-EXPIRED' => [
    'message' => 'The reset token has expired.',
    'httpStatus' => 410,
],
'TOKEN-ALREADY-USED' => [
    'message' => 'The reset token has already been used.',
    'httpStatus' => 409,
],
```

## Related

- `docs/development/invitation-tokens.md` — one-time token pattern (similar expiry/claim mechanics)
- `docs/development/error-codes.md` — full error code catalog
- `class/kit/PasswordResetToken.php` — framework helper implementation
- `class/xion/Mailer.php` — email sending
