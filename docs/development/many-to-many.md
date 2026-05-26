# Many-to-Many Relationships

How to model M:N relationships in NeNe using a join table: add/remove membership, filter by membership, and avoid row duplication on multi-group JOIN queries.

The examples use a contact–group scenario (contacts belong to multiple groups per owner), but the pattern applies to any M:N: posts–tags, users–roles, products–categories.

## Schema

```php
// class/xion/SchemaDefinition.php

'contacts' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'updated_at' => ['type' => 'datetime-touch'],
        'owner_id'   => ['type' => 'bigint'],
        'name'       => ['type' => 'varchar:255'],
        'email'      => ['type' => 'varchar:255'],
        'phone'      => ['type' => 'varchar:64'],
        'notes'      => ['type' => 'text'],
    ],
    'indexes' => [
        'contacts_owner_id_index' => ['owner_id'],
    ],
],
'contact_groups' => [
    'columns' => [
        'id'         => ['type' => 'pk-bigint'],
        'created_at' => ['type' => 'datetime-now'],
        'owner_id'   => ['type' => 'bigint'],
        'name'       => ['type' => 'varchar:128'],
    ],
    'unique' => [
        'contact_groups_owner_name_unique' => ['owner_id', 'name'],
    ],
],
'contact_group_members' => [
    'columns' => [
        'contact_id' => ['type' => 'bigint'],
        'group_id'   => ['type' => 'bigint'],
    ],
    'unique' => [
        'contact_group_members_pk' => ['contact_id', 'group_id'],
    ],
],
```

> `contact_group_members` has no `id` or `created_at` — the composite primary key is the pair `(contact_id, group_id)`. Add `created_at` only if you need membership timestamps (e.g., "added to group on ...").

## Mapper: add to group (idempotent)

Use `INSERT OR IGNORE` (SQLite) or `INSERT IGNORE` (MySQL) so adding a contact to a group it's already in is a no-op:

```php
// SQLite
public function addToGroup(int $contactId, int $groupId): void
{
    $stmt = $this->DB->prepare('
        INSERT OR IGNORE INTO contact_group_members (contact_id, group_id)
        VALUES (:cid, :gid)
    ');
    $stmt->bindValue(':cid', $contactId, PDO::PARAM_INT);
    $stmt->bindValue(':gid', $groupId,   PDO::PARAM_INT);
    $this->execute($stmt);
}

// MySQL equivalent
// INSERT IGNORE INTO contact_group_members (contact_id, group_id) VALUES (?, ?)
```

## Mapper: remove from group

```php
public function removeFromGroup(int $contactId, int $groupId): bool
{
    $stmt = $this->DB->prepare('
        DELETE FROM contact_group_members WHERE contact_id = :cid AND group_id = :gid
    ');
    $stmt->bindValue(':cid', $contactId, PDO::PARAM_INT);
    $stmt->bindValue(':gid', $groupId,   PDO::PARAM_INT);
    $this->execute($stmt);
    return $stmt->rowCount() > 0;
}
```

## Mapper: load contact with its groups

Load the contact row first, then load its groups in a second query. This avoids JOIN duplication (a contact in 3 groups returns 3 rows with JOINs):

```php
public function findWithGroups(int $contactId, int $ownerId): ?array
{
    // Ownership check built into the WHERE clause (IDOR prevention)
    $stmt = $this->DB->prepare('
        SELECT * FROM contacts WHERE id = :id AND owner_id = :owner LIMIT 1
    ');
    $stmt->bindValue(':id',    $contactId, PDO::PARAM_INT);
    $stmt->bindValue(':owner', $ownerId,   PDO::PARAM_INT);
    $contact = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($contact)) {
        return null;
    }

    $gStmt = $this->DB->prepare('
        SELECT cg.id, cg.name
        FROM contact_groups cg
        JOIN contact_group_members m ON m.group_id = cg.id
        WHERE m.contact_id = :cid
        ORDER BY cg.name ASC
    ');
    $gStmt->bindValue(':cid', $contactId, PDO::PARAM_INT);
    $contact['groups'] = $this->execute($gStmt)->fetchAll(PDO::FETCH_ASSOC);

    return $contact;
}
```

## Mapper: filter contacts by group membership

Use an `EXISTS` subquery instead of a JOIN. A JOIN with a join table returns one row per membership — if a contact is in 3 groups and you filter by one group, a JOIN still returns 1 row (correct), but if you filter by multiple groups simultaneously the JOIN requires `HAVING COUNT(DISTINCT group_id) = N` to avoid false positives. EXISTS is semantically cleaner:

```php
public function findByGroup(int $ownerId, int $groupId): array
{
    $stmt = $this->DB->prepare('
        SELECT c.*
        FROM contacts c
        WHERE c.owner_id = :owner
          AND EXISTS (
              SELECT 1 FROM contact_group_members m
              WHERE m.contact_id = c.id AND m.group_id = :gid
          )
        ORDER BY c.name ASC
    ');
    $stmt->bindValue(':owner', $ownerId, PDO::PARAM_INT);
    $stmt->bindValue(':gid',   $groupId, PDO::PARAM_INT);
    return $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
```

## Mapper: delete contact with cascade

When deleting a contact, remove its memberships first (or use `ON DELETE CASCADE` if your schema supports it):

```php
public function deleteWithMemberships(int $contactId, int $ownerId): bool
{
    $transaction = new \Nene\Xion\TransactionManager();
    return $transaction->run(function () use ($contactId, $ownerId): bool {
        // Confirm ownership before deleting
        $row = $this->findByIdAndOwner($contactId, $ownerId);
        if ($row === null) {
            return false; // caller returns 404
        }

        // Remove memberships
        $del1 = $this->DB->prepare('DELETE FROM contact_group_members WHERE contact_id = :cid');
        $del1->bindValue(':cid', $contactId, PDO::PARAM_INT);
        $this->execute($del1);

        // Remove contact
        $del2 = $this->DB->prepare('DELETE FROM contacts WHERE id = :id AND owner_id = :owner');
        $del2->bindValue(':id',    $contactId, PDO::PARAM_INT);
        $del2->bindValue(':owner', $ownerId,   PDO::PARAM_INT);
        $this->execute($del2);

        return true;
    });
}
```

## SQLite LIKE case-sensitivity caution

SQLite's `LIKE` is case-insensitive for ASCII by default. Searching for `q=Bob` matches both `Bob Smith` (name) and `bob@example.com` (email). This can cause unexpected cross-row matches in tests.

MySQL's `LIKE` is also case-insensitive by default on `utf8mb4_general_ci`. Use `LOWER(column) LIKE LOWER(:q)` for explicit case-insensitive behavior, or use the collation that matches your needs.

In tests: ensure each row has distinct enough test data that a search for one row's term does not accidentally match another.

## Error codes

```php
// config/error_codes.php
'CONTACT-NOT-FOUND'        => ['message' => 'Contact not found.',       'httpStatus' => 404],
'CONTACT-GROUP-NOT-FOUND'  => ['message' => 'Group not found.',         'httpStatus' => 404],
'CONTACT-ALREADY-IN-GROUP' => ['message' => 'Already in this group.',   'httpStatus' => 409],
```

## Related

- `docs/development/idor-prevention.md` — ownership isolation in SQL (owner_id WHERE clause)
- `docs/development/sql-injection.md` — LIKE wildcard escaping for search
- `docs/tutorials/building-a-service.md` — TransactionManager pattern
