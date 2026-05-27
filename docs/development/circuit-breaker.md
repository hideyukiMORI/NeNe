# Circuit Breaker

`Nene\Xion\CircuitBreaker` is a DB-backed resilience primitive that prevents cascading failures when an external service (payment gateway, third-party API, etc.) becomes unavailable.

## Problem

Without protection, a slow or failing downstream service causes every request to block and fail at the network layer, consuming threads or file handles until the application itself degrades. A circuit breaker short-circuits those calls once failures accumulate, returning a fast error immediately and periodically probing for recovery.

## States

```
           failure >= threshold
  CLOSED ─────────────────────► OPEN
    ▲                              │
    │  success                     │  cooldown elapsed
    │                              ▼
    └──────────────────────── HALF-OPEN
            success               │
                                  │ failure
                                  ▼
                                OPEN  (timer resets)
```

| State | Behaviour |
| --- | --- |
| `closed` | Normal operation. Every call is allowed. Failures are counted. |
| `open` | All calls are rejected immediately. No network traffic is generated. After `openSeconds` seconds the circuit transitions to `half_open`. |
| `half_open` | One probe call is allowed. A success transitions back to `closed` and resets the counter. A failure transitions back to `open` and resets the timer. |

## Schema

Run this one-time migration before the first deployment that uses `CircuitBreaker`:

```sql
CREATE TABLE circuit_breaker_states (
    name         VARCHAR(100) NOT NULL PRIMARY KEY,
    state        VARCHAR(20)  NOT NULL DEFAULT 'closed',
    failures     INT          NOT NULL DEFAULT 0,
    opened_at    DATETIME     NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

## API

| Method | Signature | Description |
| --- | --- | --- |
| `__construct` | `(string $name, ?PDO $db, int $failureThreshold, int $openSeconds, string $table)` | `$name` is the logical service name (primary key in the table). Defaults: `$db` = `PdoConnection::getInstance()`, `$failureThreshold` = 5, `$openSeconds` = 60, `$table` = `circuit_breaker_states`. |
| `isAvailable` | `(?int $now = null): bool` | Returns `true` when a call should be attempted. Returns `false` when the circuit is OPEN and the cooldown has not elapsed. Side-effect: if the cooldown *has* elapsed, the circuit is transitioned to HALF-OPEN. |
| `recordSuccess` | `(): void` | Closes the circuit and resets the failure counter. Call after every successful downstream response. |
| `recordFailure` | `(?int $now = null): void` | Increments the failure counter. Opens the circuit when the counter reaches `$failureThreshold`. The `$now` parameter is an injectable Unix timestamp (useful in tests). |
| `getState` | `(): string` | Returns the current state constant: `CircuitBreaker::STATE_CLOSED`, `STATE_OPEN`, or `STATE_HALF_OPEN`. |
| `failureCount` | `(): int` | Returns the current failure count. Returns `0` if no record exists. |
| `reset` | `(): void` | Manually closes the circuit and resets the counter. Use from admin endpoints. |

### State constants

```php
CircuitBreaker::STATE_CLOSED    // 'closed'
CircuitBreaker::STATE_OPEN      // 'open'
CircuitBreaker::STATE_HALF_OPEN // 'half_open'
```

## Usage Pattern

Wrap every external call with the same try/catch pattern:

```php
use Nene\Xion\CircuitBreaker;

$cb = new CircuitBreaker('payment-api');

if (!$cb->isAvailable()) {
    // Respond immediately without touching the network.
    JsonResponder::outputError('CIRCUIT-OPEN', 'Payment API is temporarily unavailable.');
}

try {
    $response = $httpClient->post('/charge', $payload);
    $cb->recordSuccess();
    return $response;
} catch (\Exception $e) {
    $cb->recordFailure();
    throw $e;
}
```

The `CIRCUIT-OPEN` error code maps to HTTP 503 (see `config/error_codes.php`).

## Configuration Parameters

| Parameter | Default | Notes |
| --- | --- | --- |
| `$failureThreshold` | `5` | Number of consecutive failures required to open the circuit. |
| `$openSeconds` | `60` | Seconds the circuit stays OPEN before transitioning to HALF-OPEN. |

Adjust these per service. A slow but essential service warrants a higher threshold and a shorter cooldown; a flaky low-priority service may deserve a lower threshold and a longer cooldown.

## Multiple Circuits

Each logical service should have its own named circuit. Names are arbitrary strings stored as the primary key in `circuit_breaker_states`:

```php
$paymentCb  = new CircuitBreaker('payment-api');
$shippingCb = new CircuitBreaker('shipping-api');
$smsCb      = new CircuitBreaker('sms-gateway');
```

## Manual Reset (Admin Endpoint Pattern)

Expose a protected admin endpoint to clear a circuit manually after a known incident:

```php
// AdminController — POST /admin/circuit-breaker/reset
public function resetAction(): void
{
    $name = $this->request->post('name') ?? '';
    (new CircuitBreaker($name))->reset();
    JsonResponder::output(['reset' => $name]);
}
```

Guard the endpoint with authentication and audit-log the action.

## Related

- `class/xion/CircuitBreaker.php` — implementation.
- `tests/Unit/Xion/CircuitBreakerTest.php` — unit tests (SQLite `:memory:`).
- `config/error_codes.php` — `CIRCUIT-OPEN` error code (HTTP 503).
- NENE2 FT137 — Python-side circuit breaker trial that informed this design.
- `docs/field-trials/2026-05-field-trial-43.md` — trial report.
