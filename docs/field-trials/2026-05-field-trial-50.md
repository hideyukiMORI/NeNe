# Field Trial 50 — Input Validation

**Date:** 2026-05-27
**Theme:** Fluent validator for HTTP request input — `Nene\Func\Validator`
**PR:** (pending)

---

## What was built

`Nene\Func\Validator` — a fluent, chainable input validator for controller-level HTTP request validation. Complements `DataMapperBase::isValid()` (entity schema validation) with request-level validation that produces structured, field-keyed error messages suitable for API clients.

### New class

**`class/func/Validator.php`** — final class, zero dependencies outside PHP core:

| Method | Kind | Description |
|---|---|---|
| `required(string ...$fields)` | Rule | Fails on `null`, `''`, or `[]` |
| `maxLength(string $field, int $max)` | Rule | Fails if `mb_strlen > $max`; skips absent/empty |
| `minLength(string $field, int $min)` | Rule | Fails if `mb_strlen < $min`; skips absent/empty |
| `email(string $field)` | Rule | `FILTER_VALIDATE_EMAIL`; skips absent/empty |
| `url(string $field)` | Rule | `FILTER_VALIDATE_URL`; skips absent/empty |
| `integer(string $field, ?int $min, ?int $max)` | Rule | `FILTER_VALIDATE_INT` + range; skips absent/empty |
| `in(string $field, array $allowed)` | Rule | Strict `in_array`; skips absent/empty |
| `regex(string $field, string $pattern, string $message)` | Rule | `preg_match`; custom message optional; skips absent/empty |
| `passes()` | Result | `true` if no errors recorded |
| `errors()` | Result | `array<string, list<string>>` — all messages per field |
| `firstErrors()` | Result | `array<string, string>` — one message per field |

### New error code

`VALIDATION-FAILED` (HTTP 422) added to `config/error_codes.php` and `docs/development/error-codes.md`.

### New documentation

`docs/development/input-validation.md` — explains when to use `Validator` vs `isValid()`, full API table, controller example, and `passes()` / `errors()` / `firstErrors()` outputs.

### Tests

**`tests/Unit/Func/ValidatorTest.php`** — 42 tests, covering all 8 rules, all result methods, chain fluency, multi-error accumulation, and combined rule scenarios. Zero DB, zero HTTP — pure unit tests.

---

## Findings

### F-1 — No request-level validator existed (medium, fixed)

`DataMapperBase::isValid()` validates entity data against schema constraints (type, length, NOT NULL), but this only fires at the persistence boundary. Controllers receiving invalid HTTP input had no structured way to produce field-keyed validation errors for API clients. The pattern in existing controllers was ad-hoc: manual presence checks, early returns with custom error codes.

**Fix:** `Nene\Func\Validator` provides a single place for request-level validation, producing a consistent `array<string, list<string>>` structure. Controllers now return `VALIDATION-FAILED` (422) with `firstErrors()` embedded in the response body, giving clients actionable per-field feedback.

### F-2 — Skip-on-absent semantics simplify combined rules (design note)

All rules except `required()` silently skip absent or empty fields. This means combining `required()` with a formatting rule produces the expected UX: absent → "required" error only; present but malformed → format error only. The alternative (always run all rules) would fire format errors on absent optional fields, which pollutes error output for fields the user never intended to fill.

### F-3 — `in()` strict comparison prevents type coercion bugs (design note)

`in_array` defaults to loose comparison, where `0 == 'active'` is `true` in PHP. `Validator::in()` always uses `strict: true`. HTTP input arrives as strings; allowed value arrays are typically string literals, so strict comparison is correct and avoids surprising passes.

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) | 264 tests, 466 assertions — OK |
| Phan | 0 errors (exit 0) |
| PHP syntax | `class/func/Validator.php`: no errors |
| ErrorCodeTest | `VALIDATION-FAILED` present in both PHP catalog and markdown — OK |

---

## How to use Validator in a controller

```php
$v = new \Nene\Func\Validator($this->POST->getArray(['title', 'email', 'age']));
$v->required('title', 'email')
  ->maxLength('title', 200)
  ->email('email')
  ->integer('age', min: 0, max: 150);

if (!$v->passes()) {
    $this->API_RESPONSE->set('errors', $v->firstErrors());
    $this->API_RESPONSE->failure('VALIDATION-FAILED');
    return;
}
```

---

## Related

- `class/func/Validator.php` — implementation
- `tests/Unit/Func/ValidatorTest.php` — 42 unit tests
- `config/error_codes.php` — `VALIDATION-FAILED` entry
- `docs/development/input-validation.md` — user-facing documentation
- `docs/development/error-codes.md` — error catalog
