# Booking and Reservation Systems (Double-Booking Prevention)

Reservation systems face two concurrent-write problems: **double-booking** (two users claim the same slot) and **capacity overflow** (more bookings than available seats). Both require database-level enforcement — application-level checks alone are not safe under concurrency.

## The TOCTOU problem

The typical "check then insert" flow:

```
T1: SELECT count(*) → 4 (under capacity 5)
T2: SELECT count(*) → 4 (under capacity 5)
T1: INSERT booking row    ← now 5 (at capacity)
T2: INSERT booking row    ← now 6 (over capacity! TOCTOU)
```

Both T1 and T2 pass the check, and both insert. The application must serialize the check+insert using a UNIQUE constraint, a SELECT FOR UPDATE, or a single-statement conditional INSERT.

## Pattern A — UNIQUE constraint (exclusive slots)

For resources with one slot per time unit (hotel room nights, doctor appointments, parking spaces), a UNIQUE constraint on `(resource_id, date)` is the simplest guard:

```php
// SchemaDefinition

'bookings' => [
    'columns' => [
        'id'          => ['type' => 'pk-bigint'],
        'created_at'  => ['type' => 'datetime-now'],
        'resource_id' => ['type' => 'bigint'],
        'user_id'     => ['type' => 'bigint'],
        'booked_date' => ['type' => 'varchar:10'],   // YYYY-MM-DD
    ],
    'unique' => [
        'bookings_resource_date_unique' => ['resource_id', 'booked_date'],
    ],
],
```

The controller attempts the INSERT and catches the constraint violation:

```php
public function indexPostRest(): array
{
    $resourceId = (int)($this->REQUEST_JSON['resource_id'] ?? 0);
    $date       = trim((string)($this->REQUEST_JSON['date'] ?? ''));
    $userId     = $this->getLoginUserId();

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $this->API_RESPONSE->failure('BOOKING-DATE-INVALID');
    }

    try {
        $booking = (new Database\BookingMapper())->create($resourceId, $userId, $date);
        return $this->API_RESPONSE->success(['booking' => $this->normalizeRow($booking)]);
    } catch (\PDOException $e) {
        if (str_contains($e->getCode(), '23')) { // SQLSTATE 23xxx = constraint violation
            return $this->API_RESPONSE->failure('BOOKING-ALREADY-EXISTS'); // 409
        }
        throw $e;
    }
}
```

## Pattern B — capacity check with serialized INSERT

For resources with limited capacity (events, classes, tours), a COUNT-based check must be serialized. MySQL's `SELECT ... FOR UPDATE` locks the relevant rows:

```php
public function create(int $resourceId, int $userId, string $date, int $capacity): ?array
{
    $transaction = new \Nene\Xion\TransactionManager();
    return $transaction->run(function () use ($resourceId, $userId, $date, $capacity): ?array {
        // Lock: prevents concurrent SELECTs from seeing stale count during this transaction
        $countStmt = $this->DB->prepare('
            SELECT COUNT(*) AS booked
            FROM ' . static::TARGET_TABLE . '
            WHERE resource_id = :rid AND booked_date = :date
            FOR UPDATE
        ');
        $countStmt->bindValue(':rid',  $resourceId, PDO::PARAM_INT);
        $countStmt->bindValue(':date', $date,       PDO::PARAM_STR);
        $row    = $this->execute($countStmt)->fetch(PDO::FETCH_ASSOC);
        $booked = (int)($row['booked'] ?? 0);

        if ($booked >= $capacity) {
            return null; // caller maps to 409
        }

        $insertStmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (resource_id, user_id, booked_date)
            VALUES (:rid, :uid, :date)
        ');
        $insertStmt->bindValue(':rid',  $resourceId, PDO::PARAM_INT);
        $insertStmt->bindValue(':uid',  $userId,     PDO::PARAM_INT);
        $insertStmt->bindValue(':date', $date,       PDO::PARAM_STR);
        $this->execute($insertStmt);

        return $this->findRowById((int)$this->DB->lastInsertId());
    });
}
```

> `FOR UPDATE` is MySQL only. For SQLite, use `BEGIN EXCLUSIVE` (PDO: `$this->DB->exec('BEGIN EXCLUSIVE')`) or rely on the UNIQUE constraint approach.

## Pattern C — conditional INSERT (MySQL)

A single SQL statement that inserts only if capacity is not exceeded:

```sql
INSERT INTO bookings (resource_id, user_id, booked_date)
SELECT :rid, :uid, :date
WHERE (
    SELECT COUNT(*) FROM bookings
    WHERE resource_id = :rid AND booked_date = :date
) < :capacity
```

Check `rowCount() === 1` after execution. If 0, capacity was already reached.

## Error codes

```php
// config/error_codes.php
'BOOKING-DATE-INVALID'    => ['message' => 'Booking date is not a valid date.',      'httpStatus' => 400],
'BOOKING-ALREADY-EXISTS'  => ['message' => 'This slot is already booked.',            'httpStatus' => 409],
'BOOKING-CAPACITY-FULL'   => ['message' => 'This slot is fully booked.',              'httpStatus' => 409],
```

## Distinguishing "already booked" from "capacity full"

In Pattern A (UNIQUE constraint), both "already booked by me" and "booked by someone else" result in a constraint violation — you get a 409 for both and cannot easily distinguish them without an extra query.

In Pattern B (COUNT check), you know exactly why the insert failed (capacity hit). If you also need "I already booked this" detection, add a UNIQUE on `(resource_id, user_id, booked_date)` for per-user exclusivity.

## Testing

Always test the race condition path:

```php
// Simulate concurrent bookings: create two identical requests
$firstResponse  = $this->client->json('POST', '/booking/index', ['resource_id' => 1, 'date' => '2026-07-01']);
$secondResponse = $this->client->json('POST', '/booking/index', ['resource_id' => 1, 'date' => '2026-07-01']);

self::assertSame(200, $firstResponse->statusCode());
self::assertSame(409, $secondResponse->statusCode());
self::assertSame('BOOKING-ALREADY-EXISTS', $secondResponse->json()['Data']['errorCode']);
```

In real concurrency tests, use goroutines/threads or parallel curl. In sequential tests, verify the second attempt is rejected.

## Related

- `docs/development/ledger-systems.md` — idempotency key pattern for duplicate-request prevention
- `docs/development/idor-prevention.md` — ownership isolation
- `docs/tutorials/building-a-service.md` — TransactionManager pattern
