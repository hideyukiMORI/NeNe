# Temporal Data (Date-Effective Records)

Temporal data tracks how a value changes over time: product prices, tax rates, user tier benefits, permission policies. Instead of overwriting a single row, each change inserts a new row with an effective date range.

## The pattern

Each temporal record has `effective_from` and `effective_to` columns. The active record is the one where `NOW()` falls within the range. When a new record is created, the previous open-ended record is closed.

```
id  effective_from      effective_to        price
1   2026-01-01          2026-03-31          980
2   2026-04-01          2026-05-31          1200
3   2026-06-01          NULL                1100   ← current
```

`NULL` in `effective_to` means "open-ended" (no planned end date yet).

## Schema

```php
// class/xion/SchemaDefinition.php

'price_tiers' => [
    'columns' => [
        'id'             => ['type' => 'pk-bigint'],
        'created_at'     => ['type' => 'datetime-now'],
        'product_id'     => ['type' => 'bigint'],
        'price'          => ['type' => 'bigint'],             // price in cents / lowest unit
        'effective_from' => ['type' => 'varchar:10'],         // YYYY-MM-DD
        'effective_to'   => ['type' => 'varchar:10', 'nullable' => true], // NULL = open-ended
    ],
    'indexes' => [
        'price_tiers_product_id_index' => ['product_id'],
    ],
],
```

Store dates as `VARCHAR(10)` in `YYYY-MM-DD` ISO format rather than `DATETIME` for date-only precision. MySQL and SQLite compare ISO date strings lexicographically correctly.

## Mapper — current record lookup

```php
// Current price for a product (as of today)
public function findCurrentByProductId(int $productId): ?array
{
    $today = (new \DateTime())->format('Y-m-d');
    $stmt  = $this->DB->prepare('
        SELECT id, product_id, price, effective_from, effective_to
        FROM ' . static::TARGET_TABLE . '
        WHERE product_id = :product_id
          AND effective_from <= :today
          AND (effective_to IS NULL OR effective_to >= :today)
        LIMIT 1
    ');
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':today',      $today,     PDO::PARAM_STR);
    $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

// Price at a specific date (historical query)
public function findByProductIdAtDate(int $productId, string $date): ?array
{
    $stmt = $this->DB->prepare('
        SELECT id, product_id, price, effective_from, effective_to
        FROM ' . static::TARGET_TABLE . '
        WHERE product_id = :product_id
          AND effective_from <= :date
          AND (effective_to IS NULL OR effective_to >= :date)
        LIMIT 1
    ');
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':date',       $date,      PDO::PARAM_STR);
    $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
```

## Mapper — insert new tier (auto-close previous)

```php
public function createTier(int $productId, int $price, string $effectiveFrom): array
{
    $transaction = new \Nene\Xion\TransactionManager();
    return $transaction->run(function () use ($productId, $price, $effectiveFrom): array {
        // Close the current open-ended tier one day before the new one starts
        $prevEnd = (new \DateTime($effectiveFrom))
            ->modify('-1 day')
            ->format('Y-m-d');
        $closeStmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET effective_to = :prev_end
            WHERE product_id = :product_id
              AND effective_to IS NULL
        ');
        $closeStmt->bindValue(':prev_end',   $prevEnd,   PDO::PARAM_STR);
        $closeStmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $this->execute($closeStmt);

        // Insert the new tier
        $insertStmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (product_id, price, effective_from)
            VALUES (:product_id, :price, :effective_from)
        ');
        $insertStmt->bindValue(':product_id',    $productId,    PDO::PARAM_INT);
        $insertStmt->bindValue(':price',         $price,        PDO::PARAM_INT);
        $insertStmt->bindValue(':effective_from', $effectiveFrom, PDO::PARAM_STR);
        $this->execute($insertStmt);

        return $this->findRowById((int)$this->DB->lastInsertId());
    });
}
```

## Design decisions

| Decision | Recommendation |
|---|---|
| Date format | `YYYY-MM-DD` string (VARCHAR 10). Lexicographic comparison works correctly for ISO dates. |
| Open-ended sentinel | `NULL` in `effective_to`. Do not use a magic far-future date (`9999-12-31`) — NULL is unambiguous and queries are cleaner. |
| Half-open vs closed interval | Use a **closed interval** (`effective_from <= date AND effective_to >= date`). This avoids gaps on day boundaries. |
| Auto-close previous | Do it in a transaction with the insert of the new tier. Never leave overlapping open-ended records. |
| Historical query | Always query with a specific date parameter — never use `NOW()` in historical lookups. |

## Validation

When accepting `effective_from` from client input, validate the date format:

```php
$from = trim((string)($this->REQUEST_JSON['effective_from'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) {
    return $this->API_RESPONSE->failure('PRICE-DATE-INVALID');
}
// Also check: new tier must start after the current active tier's effective_from
```

## History listing

```sql
SELECT id, price, effective_from, effective_to
FROM price_tiers
WHERE product_id = ?
ORDER BY effective_from DESC
```

## Related

- `docs/development/ledger-systems.md` — append-only immutable records
- `docs/development/state-machines.md` — lifecycle patterns
- `docs/tutorials/building-a-service.md` — TransactionManager pattern
