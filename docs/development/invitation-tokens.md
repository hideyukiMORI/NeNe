# Invitation Token Systems

How to implement one-time invitation codes: generate, claim (with expiry), and revoke. Pair with **`docs/development/ledger-systems.md`** for the idempotency key pattern.

## Schema

```php
// class/xion/SchemaDefinition.php

'invitations' => [
    'columns' => [
        'id'          => ['type' => 'pk-bigint'],
        'created_at'  => ['type' => 'datetime-now'],
        'updated_at'  => ['type' => 'datetime-touch'],
        'code'        => ['type' => 'varchar:64'],           // random token
        'issuer_id'   => ['type' => 'bigint'],
        'status'      => ['type' => 'varchar:16'],           // pending | claimed | revoked
        'claimed_by'  => ['type' => 'bigint', 'nullable' => true],
        'claimed_at'  => ['type' => 'varchar:19', 'nullable' => true],
        'expires_at'  => ['type' => 'varchar:19', 'nullable' => true], // NULL = never expires
    ],
    'unique' => [
        'invitations_code_unique' => ['code'],
    ],
],
```

Valid `status` values: `pending` → `claimed` or `revoked`. Only `pending` codes can be claimed or revoked.

## Code generation

```php
// Generate a cryptographically random 32-hex code
$code = bin2hex(random_bytes(16)); // 32 characters, negligible collision probability
```

Do not use `uniqid()`, `md5(time())`, or sequential integers — these are predictable.

## Mapper

```php
// class/db/InvitationMapper.php

class InvitationMapper extends \Nene\Xion\DataMapperBase
{
    const TARGET_TABLE = 'invitations';
    const KEY_SID      = 'id';

    public function create(int $issuerId, ?string $expiresAt): array
    {
        $code = bin2hex(random_bytes(16));
        $stmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . '
                (code, issuer_id, status, expires_at)
            VALUES (:code, :issuer, :status, :expires)
        ');
        $stmt->bindValue(':code',    $code,      PDO::PARAM_STR);
        $stmt->bindValue(':issuer',  $issuerId,  PDO::PARAM_INT);
        $stmt->bindValue(':status',  'pending',  PDO::PARAM_STR);
        $stmt->bindValue(':expires', $expiresAt, $expiresAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $this->execute($stmt);
        return $this->findRowById((int)$this->DB->lastInsertId());
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . ' WHERE code = :code LIMIT 1
        ');
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return 'ok'|'not_found'|'already_used'|'expired' */
    public function claim(string $code, int $claimedBy): string
    {
        $row = $this->findByCode($code);
        if ($row === null) {
            return 'not_found';
        }
        if ($row['status'] !== 'pending') {
            return 'already_used';
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < (new \DateTime())->format('Y-m-d H:i:s')) {
            return 'expired';
        }

        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET status = :status, claimed_by = :uid, claimed_at = :now
            WHERE code = :code AND status = :pending
        ');
        $stmt->bindValue(':status',  'claimed',                                    PDO::PARAM_STR);
        $stmt->bindValue(':uid',     $claimedBy,                                   PDO::PARAM_INT);
        $stmt->bindValue(':now',     (new \DateTime())->format('Y-m-d H:i:s'),     PDO::PARAM_STR);
        $stmt->bindValue(':code',    $code,                                        PDO::PARAM_STR);
        $stmt->bindValue(':pending', 'pending',                                    PDO::PARAM_STR);
        $this->execute($stmt);

        return 'ok';
    }

    /** @return 'ok'|'not_found'|'not_pending' */
    public function revoke(string $code, int $issuerId): string
    {
        $row = $this->findByCode($code);
        if ($row === null || (int)$row['issuer_id'] !== $issuerId) {
            return 'not_found'; // issuer mismatch → 404 (not 403, avoid info leak)
        }
        if ($row['status'] !== 'pending') {
            return 'not_pending';
        }

        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET status = :status
            WHERE code = :code AND issuer_id = :issuer AND status = :pending
        ');
        $stmt->bindValue(':status',  'revoked',  PDO::PARAM_STR);
        $stmt->bindValue(':code',    $code,      PDO::PARAM_STR);
        $stmt->bindValue(':issuer',  $issuerId,  PDO::PARAM_INT);
        $stmt->bindValue(':pending', 'pending',  PDO::PARAM_STR);
        $this->execute($stmt);

        return 'ok';
    }
}
```

## Controller: claim endpoint

```php
// POST /invitation/claim/code_{code}
public function claimPostRest(string $code): array
{
    $claimedBy = $this->getLoginUserId();

    $result = (new Database\InvitationMapper())->claim($code, $claimedBy);

    return match ($result) {
        'ok'         => $this->API_RESPONSE->success([]),
        'not_found'  => $this->API_RESPONSE->failure('INVITATION-NOT-FOUND'),
        'already_used' => $this->API_RESPONSE->failure('INVITATION-ALREADY-USED'),
        'expired'    => $this->API_RESPONSE->failure('INVITATION-EXPIRED'),
    };
}
```

## Controller: revoke endpoint (issuer only)

```php
// DELETE /invitation/revoke/code_{code}
public function revokeDeleteRest(string $code): array
{
    $issuerId = $this->getLoginUserId();

    $result = (new Database\InvitationMapper())->revoke($code, $issuerId);

    return match ($result) {
        'ok'          => $this->API_RESPONSE->success([]),
        'not_found'   => $this->API_RESPONSE->failure('INVITATION-NOT-FOUND'),
        'not_pending' => $this->API_RESPONSE->failure('INVITATION-NOT-PENDING'),
    };
}
```

## Error codes

```php
// config/error_codes.php
'INVITATION-NOT-FOUND'    => ['message' => 'Invitation code not found.',               'httpStatus' => 404],
'INVITATION-ALREADY-USED' => ['message' => 'This invitation has already been used.',   'httpStatus' => 409],
'INVITATION-EXPIRED'      => ['message' => 'This invitation has expired.',             'httpStatus' => 409],
'INVITATION-NOT-PENDING'  => ['message' => 'This invitation is no longer pending.',    'httpStatus' => 409],
```

## Security notes

- **Return 404, not 403, when an issuer tries to revoke someone else's code.** Returning 403 confirms the code exists and belongs to a different issuer — an information leak.
- **The `WHERE code = :code AND status = :pending` guard in the UPDATE** prevents a race condition where two simultaneous claims could both succeed (each checks status before the other updates it). Both cannot match after the first UPDATE — the second UPDATE affects 0 rows.
- **Never expose the `claimed_by` user ID in responses to non-issuers.** Claiming proves the code is valid; who claimed it is the issuer's business only.
- **Set a short expiry for registration invitations.** 24–72 hours is typical. A null `expires_at` is appropriate only for special cases (admin-issued permanent access, beta testers).

## Expiry format

Store `expires_at` as `YYYY-MM-DD HH:MM:SS` UTC string. String comparison with `<` works correctly for lexicographic ISO datetime ordering:

```php
if ($row['expires_at'] !== null && $row['expires_at'] < (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')) {
    return 'expired';
}
```

## Related

- `docs/development/ledger-systems.md` — idempotency key pattern (UNIQUE constraint prevents double-claim)
- `docs/development/idor-prevention.md` — ownership isolation (issuer can only revoke their own codes)
- `docs/development/state-machines.md` — status transition patterns
