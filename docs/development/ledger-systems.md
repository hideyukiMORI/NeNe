# Append-Only Ledger Systems

An append-only ledger stores every financial or point transaction as an immutable row. The current balance is always computed with `SUM()` rather than stored in a column. This design prevents silent balance corrections, guarantees a complete audit trail, and makes concurrency bugs visible.

Use this pattern for: reward credits, wallet balances, loyalty points, virtual currency, account journals.

## Core principles

1. **Never UPDATE or DELETE ledger rows.** Every change is a new row.
2. **Balance is computed on read.** `SELECT COALESCE(SUM(amount * direction), 0) FROM ledger WHERE user_id = ?`
3. **Direction encodes sign.** `direction = 1` for credits (earn), `direction = -1` for debits (spend).
4. **Idempotency key prevents duplicates.** Client-supplied unique key on writes; duplicate submission replays the original response.

## Schema

```php
// class/xion/SchemaDefinition.php

'credit_transactions' => [
    'columns' => [
        'id'              => ['type' => 'pk-bigint'],
        'created_at'      => ['type' => 'datetime-now'],
        'user_id'         => ['type' => 'bigint'],
        'type'            => ['type' => 'varchar:16'],   // 'earn' | 'spend' | 'adjust'
        'amount'          => ['type' => 'bigint'],        // always positive
        'direction'       => ['type' => 'bigint'],        // 1 or -1
        'description'     => ['type' => 'varchar:255'],
        'idempotency_key' => ['type' => 'varchar:128', 'nullable' => true],
    ],
    'indexes' => [
        'credit_transactions_user_id_index' => ['user_id'],
    ],
    'unique' => [
        'credit_transactions_idempotency_key_unique' => ['idempotency_key'],
    ],
    'foreign_keys' => [
        'credit_transactions_user_id_foreign' => [
            'columns'    => ['user_id'],
            'references' => ['users', ['id']],
            'on_delete'  => 'CASCADE',
        ],
    ],
],
```

MySQL CHECK constraints (not available in SchemaDefinition) can enforce `direction IN (1, -1)` and `amount > 0` — add these to the raw SQL migration if needed.

## Mapper

```php
// class/db/CreditTransactionMapper.php

public function getBalance(int $userId): int
{
    $stmt = $this->DB->prepare('
        SELECT COALESCE(SUM(amount * direction), 0) AS bal
        FROM ' . static::TARGET_TABLE . '
        WHERE user_id = :user_id
    ');
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    return (int)($row['bal'] ?? 0);
}

/**
 * @return array<string,mixed> Inserted transaction row.
 * @throws \RuntimeException on duplicate idempotency_key (catch PDOException with SQLSTATE 23000).
 */
public function earn(int $userId, int $amount, string $description, ?string $idempotencyKey = null): array
{
    $stmt = $this->DB->prepare('
        INSERT INTO ' . static::TARGET_TABLE . ' (user_id, type, amount, direction, description, idempotency_key)
        VALUES (:user_id, :type, :amount, 1, :description, :idempotency_key)
    ');
    $stmt->bindValue(':user_id',         $userId,          PDO::PARAM_INT);
    $stmt->bindValue(':type',            'earn',           PDO::PARAM_STR);
    $stmt->bindValue(':amount',          $amount,          PDO::PARAM_INT);
    $stmt->bindValue(':description',     $description,     PDO::PARAM_STR);
    $stmt->bindValue(':idempotency_key', $idempotencyKey,  PDO::PARAM_STR);
    $this->execute($stmt);
    return $this->findRowById((int)$this->DB->lastInsertId());
}

/**
 * @return array<string,mixed>|null Transaction row, or null if insufficient balance.
 */
public function spend(int $userId, int $amount, string $description, ?string $idempotencyKey = null): ?array
{
    if ($this->getBalance($userId) < $amount) {
        return null; // insufficient balance
    }
    $stmt = $this->DB->prepare('
        INSERT INTO ' . static::TARGET_TABLE . ' (user_id, type, amount, direction, description, idempotency_key)
        VALUES (:user_id, :type, :amount, -1, :description, :idempotency_key)
    ');
    $stmt->bindValue(':user_id',         $userId,         PDO::PARAM_INT);
    $stmt->bindValue(':type',            'spend',         PDO::PARAM_STR);
    $stmt->bindValue(':amount',          $amount,         PDO::PARAM_INT);
    $stmt->bindValue(':description',     $description,    PDO::PARAM_STR);
    $stmt->bindValue(':idempotency_key', $idempotencyKey, PDO::PARAM_STR);
    $this->execute($stmt);
    return $this->findRowById((int)$this->DB->lastInsertId());
}

public function findByIdempotencyKey(string $key): ?array
{
    $stmt = $this->DB->prepare('
        SELECT * FROM ' . static::TARGET_TABLE . ' WHERE idempotency_key = :key LIMIT 1
    ');
    $stmt->bindValue(':key', $key, PDO::PARAM_STR);
    $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
```

## Controller — idempotent earn

```php
public function earnPostRest(): array
{
    $userId          = $this->getLoginUserId();
    $amount          = (int)($this->REQUEST_JSON['amount']          ?? 0);
    $description     = trim((string)($this->REQUEST_JSON['description']     ?? ''));
    $idempotencyKey  = trim((string)($this->REQUEST_JSON['idempotency_key'] ?? '')) ?: null;

    if ($amount <= 0) {
        return $this->API_RESPONSE->failure('CREDIT-AMOUNT-INVALID');
    }

    $mapper = new Database\CreditTransactionMapper();

    // Idempotency: replay existing transaction on duplicate key
    if ($idempotencyKey !== null) {
        $existing = $mapper->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->API_RESPONSE->success(['transaction' => $this->normalizeRow($existing)]);
        }
    }

    try {
        $transaction = $mapper->earn($userId, $amount, $description, $idempotencyKey);
    } catch (\PDOException $e) {
        // UNIQUE constraint on idempotency_key — race condition between check and insert
        if ($idempotencyKey !== null && str_contains($e->getMessage(), '23000')) {
            $existing = $mapper->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->API_RESPONSE->success(['transaction' => $this->normalizeRow($existing)]);
            }
        }
        throw $e;
    }

    return $this->API_RESPONSE->success(['transaction' => $this->normalizeRow($transaction)]);
}
```

## Balance concurrency

The `getBalance()` + `spend()` sequence has a TOCTOU window: two concurrent spends can both pass the balance check and both insert. For strict balance enforcement, use a database transaction:

```php
$transaction->run(function () use ($mapper, $userId, $amount, $description): array {
    $balance = $mapper->getBalanceForUpdate($userId); // SELECT ... FOR UPDATE (MySQL only)
    if ($balance < $amount) {
        throw new \DomainException('CREDIT-INSUFFICIENT');
    }
    return $mapper->spend($userId, $amount, $description);
});
```

For SQLite or low-volume scenarios, the optimistic approach (check then insert, accept rare inconsistency in tests) is simpler. Use `FOR UPDATE` only when strict serialization is required.

## Query patterns

```sql
-- Balance
SELECT COALESCE(SUM(amount * direction), 0) AS balance
FROM credit_transactions
WHERE user_id = ?;

-- History (most recent first)
SELECT id, type, amount, direction, description, created_at
FROM credit_transactions
WHERE user_id = ?
ORDER BY id DESC;

-- Filtered by type
SELECT * FROM credit_transactions
WHERE user_id = ? AND type = 'spend'
ORDER BY id DESC;
```

## Related

- `docs/development/idor-prevention.md` — ownership isolation pattern
- `docs/tutorials/building-a-service.md` — mapper and transaction patterns
