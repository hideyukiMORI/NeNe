# Personal Data Export

`Nene\Func\PersonalDataExport` is a GDPR Article 20-style personal data portability helper. It aggregates user data from multiple registered provider closures and assembles it into a portable JSON structure.

## When to use

Use `PersonalDataExport` when you need to:

- Respond to user data portability requests (GDPR Article 20, CCPA)
- Build a "Download your data" feature
- Aggregate data spread across multiple tables into one JSON file
- Test data exports without coupling the aggregator to specific database schemas

## API

```php
use Nene\Func\PersonalDataExport;
```

| Method | Description |
| --- | --- |
| `register(string $section, callable $provider): static` | Register a provider for a section. Returns `$this` for fluent chaining. |
| `export(int\|string $userId): array` | Run all providers and return the aggregated array. |
| `exportJson(int\|string $userId, int $flags = …): string` | Run all providers and return a JSON string. |
| `sections(): array` | Return registered section names in registration order (for tests). |

## Registering providers

Register one provider per logical data category at bootstrap or in a service initializer:

```php
$export = new PersonalDataExport();

$export
    ->register('profile', function (int|string $userId) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT name, email, created_at FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    })
    ->register('orders', function (int|string $userId) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT id, total, status, created_at FROM orders WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    })
    ->register('activity', function (int|string $userId) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT event, occurred_at FROM activity_log WHERE user_id = ? ORDER BY occurred_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    });
```

- Providers fire in **registration order**.
- Registering the same section name twice **overwrites** the previous provider.
- The provider's return value can be any array shape — a flat record, a list of rows, or a nested structure.

## Generating the export

```php
// Structured array
$result = $export->export($userId);
// $result['exportedAt'] — ISO 8601 timestamp
// $result['userId']     — the requested user ID
// $result['data']       — section → data map

// JSON for download
$json = $export->exportJson($userId);
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="my-data.json"');
echo $json;
```

### Output structure

```json
{
  "exportedAt": "2026-05-27T12:00:00+00:00",
  "userId": 42,
  "data": {
    "profile": {
      "name": "Taro Yamada",
      "email": "taro@example.com",
      "created_at": "2024-01-15 09:23:00"
    },
    "orders": [
      { "id": 1, "total": 1500, "status": "completed" }
    ],
    "activity": [
      { "event": "login", "occurred_at": "2026-05-27 10:00:00" }
    ]
  }
}
```

## Custom JSON flags

`exportJson()` defaults to `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`. Pass custom flags for compact output or different encoding:

```php
// Compact JSON
$json = $export->exportJson($userId, 0);

// Compact + Unicode-safe
$json = $export->exportJson($userId, JSON_UNESCAPED_UNICODE);
```

`JSON_THROW_ON_ERROR` is always added internally — encoding errors throw `\JsonException`.

## Provider signature

Every provider receives one argument: the user ID passed to `export()` or `exportJson()`.

```php
$provider = function (int|string $userId): array {
    // fetch and return data for this user
    return [];
};
```

The user ID type is `int|string` to support both integer primary keys and UUID string identifiers.

## Empty sections

A provider may return `[]`. The section key is still included in the export with an empty array value. This makes the output schema predictable regardless of whether the user has data in that category.

## Testing

Because providers are plain callables, no real database is needed in tests:

```php
$export = new PersonalDataExport();

$export->register('profile', function (int|string $userId): array {
    return ['name' => 'Test User', 'userId' => $userId];
});

$result = $export->export(42);

self::assertSame(42, $result['userId']);
self::assertSame('Test User', $result['data']['profile']['name']);
```

Use `sections()` to assert provider registration without triggering data fetching:

```php
self::assertSame(['profile', 'orders'], $export->sections());
```

## Related

- `Nene\Func\I18n` — for localising the export filename or section labels.
- `Nene\Func\EventDispatcher` — emit a `user.data-exported` event after a successful export for audit logging.
