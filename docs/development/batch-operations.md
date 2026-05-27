# Batch Operations

Batch endpoints accept an array of items in a single POST request and process each item independently. A batch endpoint can partially succeed: some items may be created while others are rejected. The `Nene\Xion\BatchResult` class accumulates per-item outcomes and determines the correct HTTP status code for the response.

## Why batch operations

Without a batch endpoint, clients must issue N sequential (or parallel) requests to create N records. This is wasteful for large payloads and creates N round-trips of latency. A single batch endpoint:

- Reduces HTTP overhead for bulk inserts (e.g. importing a list of users, creating multiple TODO items at once).
- Gives callers a single response describing which items succeeded and which failed, instead of N individual success/failure responses to reassemble.
- Lets the controller apply a single authorization check and a single size-guard before entering the loop.

The trade-off is that the HTTP status code cannot be a simple 200 or 422 when some items succeed and others fail — hence the **207 Multi-Status** convention implemented here.

## Schema

`BatchResult` is a stateless helper with no database schema. No migration is needed to use it. Each endpoint that uses batch processing defines its own input schema (the array of items).

## BatchResult API

| Method | Signature | Description |
| --- | --- | --- |
| `addSuccess` | `addSuccess(int $index, mixed $data = null): void` | Record a successful item. `$index` is the zero-based position in the original request array. `$data` is the payload to return for this item (e.g. `['id' => $newId]`); omit or pass `null` to suppress the `data` key. |
| `addFailure` | `addFailure(int $index, string $errorCode, string $errorMessage = ''): void` | Record a failed item. `$errorCode` is a machine-readable code (e.g. `BATCH-ITEM-FAILED`); `$errorMessage` is a human-readable description. |
| `count` | `count(): int` | Total number of items recorded (succeeded + failed). |
| `successCount` | `successCount(): int` | Number of successful items. |
| `failureCount` | `failureCount(): int` | Number of failed items. |
| `isPartialSuccess` | `isPartialSuccess(): bool` | True if at least one item succeeded **and** at least one failed. |
| `allSucceeded` | `allSucceeded(): bool` | True if every recorded item succeeded (and at least one was recorded). |
| `allFailed` | `allFailed(): bool` | True if every recorded item failed (and at least one was recorded). |
| `httpStatus` | `httpStatus(): int` | Returns 200 (all succeeded or empty), 207 (partial success), or 422 (all failed). |
| `toArray` | `toArray(): array` | Returns `['items' => [...], 'succeeded' => int, 'failed' => int]` ready for `json_encode`. |

## HTTP status codes

| Status | Condition |
| --- | --- |
| 200 | All items succeeded, or the batch was empty. |
| 207 | At least one item succeeded and at least one failed (partial success). |
| 422 | Every item failed. |

The `httpStatus()` method handles this decision automatically; pass its return value to `http_response_code()`.

## Controller pattern

The canonical flow for a batch POST endpoint:

```php
<?php
declare(strict_types=1);

namespace Nene\Controller\Api\Todo;

use Nene\Xion\{BatchResult, ControllerBase, DomainException};

final class BatchCreateController extends ControllerBase
{
    private const MAX_ITEMS = 100;

    public function postAction(): void
    {
        // 1. Validate input size before touching any items.
        $items = $this->request->postParam('items', []);
        if (!is_array($items) || count($items) > self::MAX_ITEMS) {
            throw new DomainException('BATCH-TOO-LARGE');
        }

        // 2. Loop — accumulate results per item.
        $result = new BatchResult();
        foreach ($items as $i => $item) {
            try {
                $id = $this->mapper->create([
                    'title'   => $item['title'] ?? '',
                    'user_id' => $this->session->userId(),
                ]);
                $result->addSuccess((int)$i, ['id' => $id]);
            } catch (\Throwable $e) {
                $result->addFailure((int)$i, 'BATCH-ITEM-FAILED', $e->getMessage());
            }
        }

        // 3. Respond — single http_response_code + single json_encode.
        http_response_code($result->httpStatus());
        echo json_encode($result->toArray());
    }
}
```

### Response shape

```json
{
  "items": [
    {"index": 0, "status": "success", "data": {"id": 42}},
    {"index": 1, "status": "failure", "errorCode": "BATCH-ITEM-FAILED", "errorMessage": "TODO title is required."}
  ],
  "succeeded": 1,
  "failed": 1
}
```

## Max-items guard pattern

Always validate input size before entering the loop. Without a guard, a malicious caller can send an arbitrarily large array:

```php
if (count($items) > self::MAX_ITEMS) {
    throw new DomainException('BATCH-TOO-LARGE');
}
```

`BATCH-TOO-LARGE` returns HTTP 400 (see `config/error_codes.php`). Choose `MAX_ITEMS` based on expected payload size and per-item processing cost. 100 is a reasonable default; raise or lower per endpoint.

## Error codes

| Code | HTTP | When |
| --- | --- | --- |
| `BATCH-TOO-LARGE` | 400 | Input array exceeds the configured maximum. Thrown before the loop. |
| `BATCH-ITEM-FAILED` | 422 | A single item could not be processed. Recorded per-item via `addFailure()`. |

## Related

- `class/xion/BatchResult.php` — the value object implemented in this feature trial.
- `tests/Unit/Xion/BatchResultTest.php` — unit test suite (15 tests).
- `config/error_codes.php` — `BATCH-TOO-LARGE` and `BATCH-ITEM-FAILED` entries.
- `docs/development/error-codes.md` — error code catalog.
