# Money — Immutable Monetary Value Object

`Nene\Func\Money` is an immutable value object for monetary amounts. It stores
amounts as integers in the smallest currency unit (e.g. ¥1,000 = `1000`,
$10.00 = `1000` cents) to avoid floating-point rounding errors in financial
calculations.

---

## Why integer storage?

Floating-point numbers (`float`, `double`) cannot represent most decimal
fractions exactly. The classic example:

```php
var_dump(0.1 + 0.2 === 0.3); // bool(false)
```

In financial code this causes invisible rounding errors. A 10% tax on ¥999
calculated with floats may produce ¥99.89999… which rounds incorrectly.

By storing amounts as plain `int` (smallest unit), all arithmetic uses exact
integer operations. The only rounding decision is explicit, at the moment you
call `round()`.

---

## API reference

### Constructors

| Method | Description |
|---|---|
| `Money::of(int $amount, string $currency = 'JPY'): self` | Named constructor. `$amount` in smallest unit. |
| `Money::zero(string $currency = 'JPY'): self` | Zero-amount money in the given currency. |

### Accessors

| Method | Return type | Description |
|---|---|---|
| `amount()` | `int` | Raw integer amount. |
| `currency()` | `string` | ISO 4217 currency code. |

### Arithmetic (return new instances — original is never mutated)

| Method | Description |
|---|---|
| `add(Money $other): self` | Add. Throws on currency mismatch. |
| `subtract(Money $other): self` | Subtract. Throws on currency mismatch. |
| `multiply(float\|int $factor): self` | Multiply by scalar; truncates to `int`. |
| `round(int $mode = PHP_ROUND_HALF_UP): self` | Round the current amount (chain after `multiply()`). |
| `abs(): self` | Absolute value. |
| `negate(): self` | Flip sign. |

### Predicates

| Method | Return | Description |
|---|---|---|
| `isZero(): bool` | — | True when amount === 0. |
| `isPositive(): bool` | — | True when amount > 0. |
| `isNegative(): bool` | — | True when amount < 0. |
| `equals(Money $other): bool` | — | True when both amount AND currency match. |
| `compareTo(Money $other): int` | -1 / 0 / 1 | Spaceship comparison. Throws on currency mismatch. |

### Serialization / display

| Method | Return | Description |
|---|---|---|
| `toArray(): array{amount: int, currency: string}` | array | Suitable for JSON encoding. |
| `format(): string` | — | Human-readable string (see below). |

---

## Common patterns

### Price + tax

```php
$price = Money::of(1000, 'JPY');     // ¥1,000
$tax   = $price->multiply(0.1)->round();  // ¥100  (not 99 — round() after multiply())
$total = $price->add($tax);          // ¥1,100
echo $total->format();               // ¥1,100
```

### Discount calculation

```php
$price    = Money::of(5000, 'JPY');
$discount = $price->multiply(0.2)->round();  // ¥1,000 (20% off)
$final    = $price->subtract($discount);     // ¥4,000
```

### Comparing prices

```php
$a = Money::of(1200, 'JPY');
$b = Money::of(980, 'JPY');

if ($a->compareTo($b) > 0) {
    echo 'a is more expensive';
}
```

### Accumulating a total

```php
$total = Money::zero('JPY');
foreach ($items as $item) {
    $total = $total->add($item->price());
}
```

### Currency mismatch safety

`add()`, `subtract()`, and `compareTo()` throw `\InvalidArgumentException` when
the currencies differ. There is no implicit conversion — multi-currency
arithmetic must be handled explicitly by the application layer.

### Currency conversion (out of scope)

`Money` does not perform currency conversion. Exchange rates are volatile and
belong in a dedicated service (e.g. `ExchangeRateService`). To convert, obtain
the rate externally and multiply:

```php
$jpy   = Money::of(1000, 'JPY');
$rate  = 0.0067;  // 1 JPY = 0.0067 USD (from external service)
$cents = (int) round($jpy->amount() * $rate * 100);
$usd   = Money::of($cents, 'USD');
```

---

## JSON serialization with `toArray()`

`toArray()` returns `['amount' => int, 'currency' => string]`, suitable for
`json_encode()` directly or as part of a larger response array.

```php
$m = Money::of(1500, 'USD');
echo json_encode($m->toArray());
// {"amount":1500,"currency":"USD"}
```

To reconstruct from JSON:

```php
$data = json_decode($json, true);
$m    = Money::of($data['amount'], $data['currency']);
```

---

## `format()` behaviour per currency

`format()` requires no `intl` extension. It uses a built-in symbol table:

| Currency | Storage unit | Example input | Output |
|---|---|---|---|
| JPY | yen (integer) | `Money::of(1500, 'JPY')` | `¥1,500` |
| KRW | won (integer) | `Money::of(5000, 'KRW')` | `₩5,000` |
| USD | cents | `Money::of(150, 'USD')` | `$1.50` |
| EUR | euro-cents | `Money::of(1099, 'EUR')` | `€10.99` |
| GBP | pence | `Money::of(999, 'GBP')` | `£9.99` |
| CNY | fen (1/100 yuan) | `Money::of(3000, 'CNY')` | `¥30.00` |
| Other | — | `Money::of(100, 'XYZ')` | `100 XYZ` |

Note: USD, EUR, GBP, and CNY amounts are divided by 100 before formatting.
Store 150 cents to represent $1.50, not 1.50.

---

## Using in Mapper (store as INT in DB)

Store monetary values as `INT` (not `DECIMAL`) in your schema. Retrieve and
wrap in `Money` in the Mapper:

```php
// Schema (SchemaCompiler yaml):
// price: {type: INT, nullable: false}

// Mapper
public function mapRow(array $row): Product
{
    return new Product(
        id:    (int) $row['id'],
        price: Money::of((int) $row['price'], 'JPY'),
    );
}

// Insert
$stmt->bindValue(':price', $product->price()->amount(), PDO::PARAM_INT);
```

---

## Related

- FT26 soft-delete (`docs/development/soft-delete.md`) — refund/reversal
  workflows typically need to soft-delete an order line and insert a
  compensating Money entry with `negate()`.
- `class/func/Money.php` — implementation
- `tests/Unit/Func/MoneyTest.php` — 28 unit tests
