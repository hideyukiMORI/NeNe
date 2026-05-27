# Feature Flags

## Framework class: `FeatureFlagService`

`Nene\Func\FeatureFlagService` ships with NeNe.

| Method | Description |
|--------|-------------|
| `isEnabled(string $flag, ?int $userId): bool` | Evaluate flag for optional user context |

### Priority chain

1. **Per-user override** (`flag_overrides` table) — highest priority; works as grant or kill switch
2. **Global `is_enabled`** (`feature_flags` table)
3. **Rollout percentage** (`rollout_pct`) — deterministic bucket by `userId + flagName`
4. **Default**: `false`

```php
$flags = new FeatureFlagService();

// Global check (no user context)
if ($flags->isEnabled('maintenance-mode')) {
    return $this->API_RESPONSE->failure('MAINTENANCE');
}

// User-specific check (overrides + rollout apply)
if ($flags->isEnabled('new-checkout', $userId)) {
    // show new checkout
}
```

Feature flags (feature toggles) let you deploy code dark, enable features for specific users, or roll back instantly without a deploy. The pattern shown here uses a database for persistence with a two-level priority model: global defaults overridden by per-user settings.

## Schema

```php
// class/xion/SchemaDefinition.php

'feature_flags' => [
    'columns' => [
        'id'          => ['type' => 'pk-bigint'],
        'created_at'  => ['type' => 'datetime-now'],
        'updated_at'  => ['type' => 'datetime-touch'],
        'flag_name'   => ['type' => 'varchar:128'],
        'is_enabled'  => ['type' => 'bool', 'default' => 0],
        'rollout_pct' => ['type' => 'tinyint', 'default' => 0],
    ],
    'unique' => [
        'feature_flags_name_unique' => ['flag_name'],
    ],
],
'flag_overrides' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'flag_name'  => ['type' => 'varchar:128'],
        'user_id'    => ['type' => 'bigint'],
        'is_enabled' => ['type' => 'bool', 'default' => 0],
    ],
    'unique' => [
        'flag_overrides_flag_user_unique' => ['flag_name', 'user_id'],
    ],
],
```

## Evaluation logic

```
is_enabled(flag, user) =
  1. Per-user override exists? → use override value
  2. Global default exists?    → use global value
  3. Neither?                  → false (off by default)
```

## FeatureFlagService

```php
// class/func/FeatureFlagService.php

final class FeatureFlagService
{
    public function isEnabled(string $flagName, int $userId): bool
    {
        // Check per-user override first
        $stmt = \Nene\Xion\PdoConnection::getInstance()->prepare('
            SELECT is_enabled FROM feature_flag_overrides
            WHERE flag_name = :flag AND user_id = :uid
            LIMIT 1
        ');
        $stmt->bindValue(':flag', $flagName, \PDO::PARAM_STR);
        $stmt->bindValue(':uid',  $userId,   \PDO::PARAM_INT);
        $stmt->execute();
        $override = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($override)) {
            return (bool)$override['is_enabled'];
        }

        // Fall back to global default
        $stmt2 = \Nene\Xion\PdoConnection::getInstance()->prepare('
            SELECT is_enabled FROM feature_flags
            WHERE flag_name = :flag
            LIMIT 1
        ');
        $stmt2->bindValue(':flag', $flagName, \PDO::PARAM_STR);
        $stmt2->execute();
        $global = $stmt2->fetch(\PDO::FETCH_ASSOC);
        if (is_array($global)) {
            return (bool)$global['is_enabled'];
        }

        return false; // not found → off by default
    }
}
```

## Controller usage

```php
public function someFeatureGetRest(): array
{
    $userId = $this->getLoginUserId();

    if (!(new \Nene\Func\FeatureFlagService())->isEnabled('new-dashboard', $userId)) {
        return $this->API_RESPONSE->failure('FEATURE-NOT-AVAILABLE'); // 403 or 404
    }

    // Feature is enabled for this user — proceed
    return $this->API_RESPONSE->success(['dashboard' => $this->loadDashboard($userId)]);
}
```

## Management endpoints

Expose flag management only to admins:

```php
// POST /admin/featureflag/index  — set global default
// PUT  /admin/featureflag/override/id_{id}  — set per-user override
// DELETE /admin/featureflag/override/id_{id} — remove override (fall back to global)
```

## Upsert pattern for overrides

Setting a per-user override is an upsert: insert if not exists, update if it does:

```php
public function upsertOverride(string $flagName, int $userId, bool $isEnabled): void
{
    // MySQL: INSERT ... ON DUPLICATE KEY UPDATE
    $stmt = $this->DB->prepare('
        INSERT INTO feature_flag_overrides (flag_name, user_id, is_enabled)
        VALUES (:flag, :uid, :enabled)
        ON DUPLICATE KEY UPDATE is_enabled = :enabled
    ');
    $stmt->bindValue(':flag',    $flagName,          PDO::PARAM_STR);
    $stmt->bindValue(':uid',     $userId,            PDO::PARAM_INT);
    $stmt->bindValue(':enabled', $isEnabled ? 1 : 0, PDO::PARAM_INT);
    $this->execute($stmt);
}
```

For SQLite: use `INSERT OR REPLACE INTO ...` or the try-INSERT-catch-UPDATE pattern.

## Caching considerations

For high-traffic applications, evaluating flags per-request with two DB queries is expensive. Cache the evaluation result in Redis (NeNe already has `predis/predis`):

```php
$cacheKey = "feature:{$flagName}:user:{$userId}";
$cached   = $redis->get($cacheKey);
if ($cached !== null) {
    return (bool)(int)$cached;
}
$result = $this->evaluateFromDb($flagName, $userId);
$redis->setex($cacheKey, 60, $result ? '1' : '0'); // 60-second TTL
return $result;
```

Invalidate on flag or override change.

## When to use feature flags

| Use case | Appropriate? |
|---|---|
| Gradual rollout to % of users | ✅ (add percentage-based evaluation) |
| A/B testing | ✅ (two flag variants, assign on first evaluation) |
| Kill switch for a broken feature | ✅ |
| Config values (strings, numbers) | ❌ Use environment variables or a config table |
| Permanent feature gating (paid tiers) | ❌ Use user tier / subscription, not flags |

## Related

- `docs/development/session-storage.md` — Redis usage in NeNe
- `docs/development/ledger-systems.md` — upsert pattern with idempotency
