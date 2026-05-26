# IDOR Prevention (Object-Level Authorization)

IDOR (Insecure Direct Object Reference) is the vulnerability class where a user can access, modify, or delete another user's resource by guessing or iterating an id. It is OWASP API Security Top 10 #1 (Broken Object Level Authorization).

NeNe provides no automatic ownership guard — every controller that serves owned resources must enforce it explicitly. This guide shows the canonical pattern.

## The core rule

**Every query that returns an owned resource must scope by both `id` AND `owner_id`.**

Never fetch by id alone and check ownership after:

```php
// WRONG — information leakage: tells attacker "this id exists"
$note = $mapper->findRowById($id);
if ($note === null)             { return $this->API_RESPONSE->failure('NOTE-NOT-FOUND'); }
if ($note['user_id'] !== $uid) { return $this->API_RESPONSE->failure('NOTE-FORBIDDEN'); }

// RIGHT — returns null for both "not found" and "wrong owner"
$note = $mapper->findRowByIdAndUserId($id, $uid);
if ($note === null) { return $this->API_RESPONSE->failure('NOTE-NOT-FOUND'); }
```

The correct pattern enforces ownership at the SQL layer. Both "does not exist" and "belongs to someone else" return the same 404 — no information leakage.

## Mapper pattern

```php
// class/db/NoteMapper.php

public function findRowByIdAndUserId(int $id, int $userId): ?array
{
    $stmt = $this->DB->prepare('
        SELECT id, title, body, created_at, updated_at
        FROM ' . static::TARGET_TABLE . '
        WHERE id = :id
          AND user_id = :user_id
          AND is_deleted = 0
        LIMIT 1
    ');
    $stmt->bindValue(':id',      $id,     PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

public function updateByIdAndUserId(int $id, int $userId, string $title, string $body): ?array
{
    $stmt = $this->DB->prepare('
        UPDATE ' . static::TARGET_TABLE . '
        SET title = :title, body = :body
        WHERE id = :id AND user_id = :user_id AND is_deleted = 0
    ');
    $stmt->bindValue(':title',   $title,  PDO::PARAM_STR);
    $stmt->bindValue(':body',    $body,   PDO::PARAM_STR);
    $stmt->bindValue(':id',      $id,     PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $this->execute($stmt);
    return $stmt->rowCount() > 0 ? $this->findRowByIdAndUserId($id, $userId) : null;
}

public function softDeleteByIdAndUserId(int $id, int $userId): bool
{
    $stmt = $this->DB->prepare('
        UPDATE ' . static::TARGET_TABLE . '
        SET is_deleted = 1
        WHERE id = :id AND user_id = :user_id AND is_deleted = 0
    ');
    $stmt->bindValue(':id',      $id,     PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $this->execute($stmt);
    return $stmt->rowCount() > 0;
}

public function findRowsByUserId(int $userId): array
{
    $stmt = $this->DB->prepare('
        SELECT id, title, body, created_at, updated_at
        FROM ' . static::TARGET_TABLE . '
        WHERE user_id = :user_id AND is_deleted = 0
        ORDER BY id DESC
    ');
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);
}
```

## Controller pattern

```php
// class/controller/NoteController.php

public function itemGetRest(): array
{
    $uid = $this->getLoginUserId();
    $id  = $this->getNoteId();
    if ($id === null) {
        return $this->API_RESPONSE->failure('NOTE-ID-REQUIRED');
    }

    $note = (new Database\NoteMapper())->findRowByIdAndUserId($id, $uid);
    if ($note === null) {
        return $this->API_RESPONSE->failure('NOTE-NOT-FOUND'); // 404 — intentionally same for not-found AND wrong-owner
    }

    return $this->API_RESPONSE->success(['note' => $this->normalizeRow($note)]);
}

public function itemDeleteRest(): array
{
    $uid = $this->getLoginUserId();
    $id  = $this->getNoteId();
    if ($id === null) {
        return $this->API_RESPONSE->failure('NOTE-ID-REQUIRED');
    }

    if (!(new Database\NoteMapper())->softDeleteByIdAndUserId($id, $uid)) {
        return $this->API_RESPONSE->failure('NOTE-NOT-FOUND');
    }

    return $this->API_RESPONSE->success(['id' => $id]);
}
```

## 404 vs 403 — the correct choice

Return **404**, not 403, when the resource exists but belongs to another user.

- **403**: confirms the resource exists and the current user lacks permission — tells an attacker which ids are valid.
- **404**: indistinguishable from "id never existed" — no information leakage.

This is the RFC 9110 §15.5.4 intent: `403 Forbidden` is appropriate when the identity is known and the server chooses not to reveal further detail is impossible. For ownership isolation, `404` is the universally accepted standard (GitHub, Stripe, Google APIs all do this).

## Schema considerations

Add an indexed `user_id` column to every owned table:

```php
// class/xion/SchemaDefinition.php

'notes' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'user_id'    => ['type' => 'bigint'],        // foreign key to users.id
        'title'      => ['type' => 'varchar:255'],
        'body'       => ['type' => 'text'],
        'is_deleted' => ['type' => 'bool', 'default' => 0],
    ],
    'indexes' => [
        'notes_user_id_index' => ['user_id'],        // index ownership queries
    ],
    'foreign_keys' => [
        'notes_user_id_foreign' => [
            'columns'    => ['user_id'],
            'references' => ['users', ['id']],
            'on_delete'  => 'CASCADE',
        ],
    ],
],
```

## Testing

Every owned-resource endpoint needs at least these three test cases:

1. **Happy path** — authenticated owner can access their resource.
2. **Other user's resource** — returns 404, not 403.
3. **Non-existent id** — returns 404 (same response, same status).

If the happy-path and the cross-user test return the same 404 and are indistinguishable, the ownership is correctly implemented.

```php
public function testGetNoteReturns404ForAnotherUsersNote(): void
{
    // Log in as user A, create a note
    $clientA = $this->newClient();
    $this->loginAs($clientA, 'user-a', 'pass-a');
    $note = $this->createNote($clientA, 'Private note');

    // Log in as user B, try to fetch user A's note
    $clientB = $this->newClient();
    $this->loginAs($clientB, 'user-b', 'pass-b');
    $response = $clientB->request('GET', '/note/item/id_' . $note['id']);

    self::assertSame(404, $response->statusCode());
}
```

## Related

- `docs/development/agent-bearer-auth.md` — authentication and JWT edge cases
- `docs/tutorials/building-a-service.md` — controller and mapper patterns
- OWASP API Security Top 10 2023 — [API1:2023 Broken Object Level Authorization](https://owasp.org/API-Security/editions/2023/en/0xa1-broken-object-level-authorization/)
