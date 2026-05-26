# Session Tracking (Multi-Device Management)

How to track user sessions — tokens, heartbeats, and revocation — on top of NeNe's existing cookie-based auth. Useful for "active devices" UIs, multi-device logouts, and security audit logs.

This is an application-layer session table, not a replacement for `$_SESSION`. NeNe's PHP session cookie handles auth; this table tracks metadata about that session (IP, user agent, last seen) and supports bulk revocation.

## Schema

```php
// class/xion/SchemaDefinition.php

'user_sessions' => [
    'columns' => [
        'id'           => ['type' => 'pk-bigint'],
        'created_at'   => ['type' => 'datetime-now'],
        'updated_at'   => ['type' => 'datetime-touch'],
        'token'        => ['type' => 'varchar:128'],         // random session token
        'user_id'      => ['type' => 'bigint'],
        'ip'           => ['type' => 'varchar:45'],          // IPv4 or IPv6
        'user_agent'   => ['type' => 'text'],
        'last_seen_at' => ['type' => 'varchar:19'],          // UTC YYYY-MM-DD HH:MM:SS
        'revoked_at'   => ['type' => 'varchar:19', 'nullable' => true],  // NULL = active
    ],
    'unique' => [
        'user_sessions_token_unique' => ['token'],
    ],
    'indexes' => [
        'user_sessions_user_id_index' => ['user_id'],
    ],
],
```

`revoked_at IS NULL` means the session is active. `revoked_at IS NOT NULL` means revoked. Never delete rows — keep history for audit purposes.

## Token generation

```php
// 64-char hex string: 32 bytes of entropy, negligible collision probability
$token = bin2hex(random_bytes(32));
```

Alternatively use a UUID if your stack already has a UUID library.

## Mapper

```php
// class/db/UserSessionMapper.php

class UserSessionMapper extends \Nene\Xion\DataMapperBase
{
    const TARGET_TABLE = 'user_sessions';
    const KEY_SID      = 'id';

    public function create(int $userId, string $ip, string $userAgent): array
    {
        $token = bin2hex(random_bytes(32));
        $now   = (new \DateTime())->format('Y-m-d H:i:s');

        $stmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (token, user_id, ip, user_agent, last_seen_at)
            VALUES (:token, :uid, :ip, :ua, :now)
        ');
        $stmt->bindValue(':token', $token,     PDO::PARAM_STR);
        $stmt->bindValue(':uid',   $userId,    PDO::PARAM_INT);
        $stmt->bindValue(':ip',    $ip,        PDO::PARAM_STR);
        $stmt->bindValue(':ua',    $userAgent, PDO::PARAM_STR);
        $stmt->bindValue(':now',   $now,       PDO::PARAM_STR);
        $this->execute($stmt);

        return $this->findRowById((int)$this->DB->lastInsertId());
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . ' WHERE token = :token LIMIT 1
        ');
        $stmt->bindValue(':token', $token, PDO::PARAM_STR);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Update last_seen_at without fetching the full row */
    public function heartbeat(string $token): bool
    {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET last_seen_at = :now
            WHERE token = :token AND revoked_at IS NULL
        ');
        $stmt->bindValue(':now',   (new \DateTime())->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':token', $token, PDO::PARAM_STR);
        $this->execute($stmt);
        return $stmt->rowCount() > 0; // false → token not found or already revoked
    }

    /** Revoke a single session; returns false if token not found or already revoked */
    public function revoke(string $token): bool
    {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET revoked_at = :now
            WHERE token = :token AND revoked_at IS NULL
        ');
        $stmt->bindValue(':now',   (new \DateTime())->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':token', $token, PDO::PARAM_STR);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }

    /** Revoke all active sessions for a user; returns count of revoked rows */
    public function revokeAll(int $userId): int
    {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET revoked_at = :now
            WHERE user_id = :uid AND revoked_at IS NULL
        ');
        $stmt->bindValue(':now', (new \DateTime())->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function findByUser(int $userId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM ' . static::TARGET_TABLE . ' WHERE user_id = :uid';
        if ($activeOnly) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->DB->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
```

## Controller: create session on login

Call this inside your login controller after verifying credentials:

```php
public function loginPostRest(): array
{
    // ... verify credentials, set AUTH_SESSION ...

    // Record the session in the tracking table
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session   = (new Database\UserSessionMapper())->create($this->getLoginUserId(), $ip, $userAgent);

    return $this->API_RESPONSE->success(['session_token' => $session['token']]);
}
```

## Controller: heartbeat

Clients should call this endpoint periodically to update `last_seen_at` (e.g., every 5 minutes):

```php
// POST /usersession/heartbeat/token_{token}
public function heartbeatPostRest(string $token): array
{
    $ok = (new Database\UserSessionMapper())->heartbeat($token);
    return $ok
        ? $this->API_RESPONSE->success([])
        : $this->API_RESPONSE->failure('SESSION-NOT-FOUND');
}
```

## Controller: revoke all (logout everywhere)

```php
// DELETE /usersession/all
public function allDeleteRest(): array
{
    $count = (new Database\UserSessionMapper())->revokeAll($this->getLoginUserId());
    return $this->API_RESPONSE->success(['revoked' => $count]);
}
```

## The `rowCount()` trick for bulk operations

`PDOStatement::rowCount()` returns the number of rows affected by the last UPDATE, DELETE, or INSERT. For bulk revocation, use this directly — no extra COUNT query needed:

```php
$stmt->execute();
$revokedCount = $stmt->rowCount(); // how many sessions were revoked
```

## Error codes

```php
// config/error_codes.php
'SESSION-NOT-FOUND' => ['message' => 'Session not found or already revoked.', 'httpStatus' => 404],
```

## Query parameter boolean coercion

If filtering by `?active=true` (string), check the exact string value:

```php
$activeOnly = ($this->REQUEST->getParam('active', '') === 'true');
```

`"1"`, `"yes"`, `"on"` do **not** match `=== 'true'`. Document the expected value explicitly in your API docs. There is no built-in query-parameter boolean coercion in NeNe.

## Related

- `docs/development/agent-bearer-auth.md` — Bearer token auth (stateless; no session table needed)
- `docs/development/idor-prevention.md` — ownership isolation (user can only see/revoke their own sessions)
- `docs/development/temporal-data.md` — date-range queries
