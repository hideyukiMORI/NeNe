# Reactions (Toggle Pattern)

How to implement emoji / like reactions on arbitrary targets: toggle semantics (first PUT adds, second PUT removes), grouped counts, and per-user current state.

## Design

- **Toggle semantics:** a single PUT endpoint adds the reaction if absent, removes it if present. Different reaction types from the same user on the same target are independent.
- **Polymorphic target:** store `target_type` + `target_id` to react on heterogeneous entities (posts, comments, replies) with a single table.
- **UNIQUE constraint as toggle key:** `(target_id, target_type, reaction_type, user_id)` prevents double reactions and is the key used to detect existing reactions.

## Schema

```php
// class/xion/SchemaDefinition.php

'reactions' => [
    'columns' => [
        'id'            => ['type' => 'pk-bigint'],
        'created_at'    => ['type' => 'datetime-now'],
        'target_id'     => ['type' => 'bigint'],
        'target_type'   => ['type' => 'varchar:64'],    // 'post', 'comment', etc.
        'reaction_type' => ['type' => 'varchar:32'],    // 'like', 'heart', 'laugh', etc.
        'user_id'       => ['type' => 'bigint'],
    ],
    'unique' => [
        'reactions_target_user_type_unique' => ['target_id', 'target_type', 'reaction_type', 'user_id'],
    ],
    'indexes' => [
        'reactions_target_index' => ['target_id', 'target_type'],
    ],
],
```

## Mapper: toggle

```php
// class/db/ReactionMapper.php

class ReactionMapper extends \Nene\Xion\DataMapperBase
{
    const TARGET_TABLE = 'reactions';
    const KEY_SID      = 'id';

    /** @return 'added'|'removed' */
    public function toggle(int $targetId, string $targetType, string $reactionType, int $userId): string
    {
        // Check if reaction exists
        $check = $this->DB->prepare('
            SELECT id FROM ' . static::TARGET_TABLE . '
            WHERE target_id = :tid AND target_type = :ttype AND reaction_type = :rtype AND user_id = :uid
            LIMIT 1
        ');
        $check->bindValue(':tid',   $targetId,     PDO::PARAM_INT);
        $check->bindValue(':ttype', $targetType,   PDO::PARAM_STR);
        $check->bindValue(':rtype', $reactionType, PDO::PARAM_STR);
        $check->bindValue(':uid',   $userId,       PDO::PARAM_INT);
        $existing = $this->execute($check)->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            // Remove existing reaction
            $del = $this->DB->prepare('DELETE FROM ' . static::TARGET_TABLE . ' WHERE id = :id');
            $del->bindValue(':id', $existing['id'], PDO::PARAM_INT);
            $this->execute($del);
            return 'removed';
        }

        // Add reaction
        $ins = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (target_id, target_type, reaction_type, user_id)
            VALUES (:tid, :ttype, :rtype, :uid)
        ');
        $ins->bindValue(':tid',   $targetId,     PDO::PARAM_INT);
        $ins->bindValue(':ttype', $targetType,   PDO::PARAM_STR);
        $ins->bindValue(':rtype', $reactionType, PDO::PARAM_STR);
        $ins->bindValue(':uid',   $userId,       PDO::PARAM_INT);
        $this->execute($ins);
        return 'added';
    }

    /**
     * @param int|null $forUserId  If provided, include user's reactions in the result.
     * @return array{total: int, by_type: array<string, int>, user_reactions: list<string>}
     */
    public function summary(int $targetId, string $targetType, ?int $forUserId = null): array
    {
        // Count by reaction type
        $stmt = $this->DB->prepare('
            SELECT reaction_type, COUNT(*) AS cnt
            FROM ' . static::TARGET_TABLE . '
            WHERE target_id = :tid AND target_type = :ttype
            GROUP BY reaction_type
        ');
        $stmt->bindValue(':tid',   $targetId,   PDO::PARAM_INT);
        $stmt->bindValue(':ttype', $targetType, PDO::PARAM_STR);
        $rows = $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);

        $byType = [];
        $total  = 0;
        foreach ($rows as $row) {
            $byType[$row['reaction_type']] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
        }

        // Per-user reactions (optional)
        $userReactions = [];
        if ($forUserId !== null) {
            $uStmt = $this->DB->prepare('
                SELECT reaction_type FROM ' . static::TARGET_TABLE . '
                WHERE target_id = :tid AND target_type = :ttype AND user_id = :uid
            ');
            $uStmt->bindValue(':tid',   $targetId,   PDO::PARAM_INT);
            $uStmt->bindValue(':ttype', $targetType, PDO::PARAM_STR);
            $uStmt->bindValue(':uid',   $forUserId,  PDO::PARAM_INT);
            $userReactions = array_column(
                $this->execute($uStmt)->fetchAll(PDO::FETCH_ASSOC),
                'reaction_type'
            );
        }

        return [
            'total'          => $total,
            'by_type'        => $byType,
            'user_reactions' => $userReactions,
        ];
    }

    /** Explicit remove (no toggle — returns false if not found) */
    public function remove(int $targetId, string $targetType, string $reactionType, int $userId): bool
    {
        $stmt = $this->DB->prepare('
            DELETE FROM ' . static::TARGET_TABLE . '
            WHERE target_id = :tid AND target_type = :ttype AND reaction_type = :rtype AND user_id = :uid
        ');
        $stmt->bindValue(':tid',   $targetId,     PDO::PARAM_INT);
        $stmt->bindValue(':ttype', $targetType,   PDO::PARAM_STR);
        $stmt->bindValue(':rtype', $reactionType, PDO::PARAM_STR);
        $stmt->bindValue(':uid',   $userId,       PDO::PARAM_INT);
        $this->execute($stmt);
        return $stmt->rowCount() > 0;
    }
}
```

## Controller

```php
// PUT /reaction/toggle/type_{targetType}/id_{targetId}/reaction_{reactionType}
public function togglePutRest(string $targetType, int $targetId, string $reactionType): array
{
    $userId = $this->getLoginUserId();

    // Allowlist reaction types
    $allowedTypes = ['like', 'heart', 'laugh', 'sad', 'wow'];
    if (!in_array($reactionType, $allowedTypes, true)) {
        return $this->API_RESPONSE->failure('REACTION-TYPE-INVALID');
    }

    $result = (new Database\ReactionMapper())->toggle($targetId, $targetType, $reactionType, $userId);

    // Optional: return different HTTP status to let callers distinguish add vs remove
    // 'added' → 201, 'removed' → 200
    $summary = (new Database\ReactionMapper())->summary($targetId, $targetType, $userId);
    return $this->API_RESPONSE->success(['action' => $result, 'summary' => $summary]);
}

// GET /reaction/summary/type_{targetType}/id_{targetId}
public function summaryGetRest(string $targetType, int $targetId): array
{
    $userId  = AUTH_SESSION->isLoggedIn() ? $this->getLoginUserId() : null;
    $summary = (new Database\ReactionMapper())->summary($targetId, $targetType, $userId);
    return $this->API_RESPONSE->success($summary);
}
```

## Response shape

```json
{
    "status": "success",
    "Data": {
        "total": 5,
        "by_type": { "like": 3, "heart": 2 },
        "user_reactions": ["like"]
    }
}
```

`user_reactions` is `[]` when no `forUserId` is provided (unauthenticated summary).

## Error codes

```php
// config/error_codes.php
'REACTION-TYPE-INVALID'  => ['message' => 'Invalid reaction type.',  'httpStatus' => 400],
'REACTION-NOT-FOUND'     => ['message' => 'Reaction not found.',     'httpStatus' => 404],
```

## API design note: toggle status codes

The toggle endpoint can return different HTTP status codes to help callers distinguish outcome without parsing the body:

| Outcome | HTTP status | Rationale |
|---|---|---|
| Reaction added | 201 Created | A new resource was created |
| Reaction removed | 200 OK | The resource was deleted |

This deviates from pure REST (PUT is normally idempotent and returns the same status), but is pragmatic for toggle APIs. An alternative is always returning 200 with `"action": "added"|"removed"` in the body.

## Related

- `docs/development/idor-prevention.md` — ownership isolation if reactions are per-owner
- `docs/development/ledger-systems.md` — idempotency key UNIQUE constraint pattern
