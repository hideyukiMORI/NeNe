# Input Validation

NeNe provides two complementary validation layers: **request-level validation** via `Nene\Func\Validator` and **entity-level validation** via `DataMapperBase::isValid()`.

## Validator vs DataMapperBase::isValid()

| Concern | Class | When to use |
|---|---|---|
| HTTP request input (presence, format, range) | `Nene\Func\Validator` | In controller actions, before constructing domain objects. Produces field-keyed error messages for API responses. |
| Entity data integrity (type, length, NOT NULL) | `DataMapperBase::isValid()` | Before persisting to the database. Guards against schema violations and programmer error. |

Use both together: `Validator` checks request data from the outside world; `isValid()` guards the persistence boundary. They serve different audiences — the Validator's errors go to the API client, while `isValid()` failures indicate a bug.

## Validator API reference

| Method | Signature | Notes |
|---|---|---|
| `required` | `required(string ...$fields): static` | Fails if value is `null`, `''`, or `[]`. |
| `maxLength` | `maxLength(string $field, int $max): static` | Fails if `mb_strlen > $max`. Skips absent/empty fields. |
| `minLength` | `minLength(string $field, int $min): static` | Fails if `mb_strlen < $min`. Skips absent/empty fields. |
| `email` | `email(string $field): static` | Uses `FILTER_VALIDATE_EMAIL`. Skips absent/empty fields. |
| `url` | `url(string $field): static` | Uses `FILTER_VALIDATE_URL`. Skips absent/empty fields. |
| `integer` | `integer(string $field, ?int $min = null, ?int $max = null): static` | Uses `FILTER_VALIDATE_INT`. Skips absent/empty fields. |
| `in` | `in(string $field, array $allowed): static` | Strict comparison (`===`). Skips absent/empty fields. |
| `regex` | `regex(string $field, string $pattern, string $message = ''): static` | Custom `$message` replaces the default "invalid format" error. Skips absent/empty fields. |

All rule methods return `static` for fluent chaining.

## Result methods

| Method | Return type | Description |
|---|---|---|
| `passes()` | `bool` | `true` if no errors were recorded. |
| `errors()` | `array<string, list<string>>` | All errors keyed by field. Each field maps to a list of error messages (a field can fail multiple rules). |
| `firstErrors()` | `array<string, string>` | One error per field — the first recorded message. Useful for simple form error displays. |

### Example outputs

```php
// errors()
[
    'title' => ['The title field is required.'],
    'email' => [
        'The email field must be a valid email address.',
    ],
]

// firstErrors()
[
    'title' => 'The title field is required.',
    'email' => 'The email field must be a valid email address.',
]
```

## Controller example

```php
public function postAction(): void
{
    $input = $this->POST->getArray(['title', 'email', 'age', 'status']);

    $v = new \Nene\Func\Validator($input);
    $v->required('title', 'email')
      ->maxLength('title', 200)
      ->minLength('title', 3)
      ->email('email')
      ->integer('age', min: 0, max: 150)
      ->in('status', ['draft', 'published']);

    if (!$v->passes()) {
        // Return structured errors to the client
        $this->API_RESPONSE->set('errors', $v->firstErrors());
        $this->API_RESPONSE->failure('VALIDATION-FAILED');
        return;
    }

    // Proceed with validated data
    $article = new Article();
    $article->title  = $input['title'];
    $article->email  = $input['email'];
    // ...
    $this->mapper->insert($article);
    $this->API_RESPONSE->success();
}
```

The `VALIDATION-FAILED` error code returns HTTP 422 Unprocessable Entity. The field-level errors can be embedded in the response body as additional data before calling `failure()`.

## Custom error messages with regex()

When `regex()` is called without a custom message, the default error reads `"The {field} field has an invalid format."`. Pass a third argument to customise:

```php
$v->regex('postcode', '/^\d{3}-\d{4}$/', 'Postcode must be in NNN-NNNN format.');
```

## Multiple errors on one field

Every rule that fails appends a message independently. `errors()` returns all messages for a field:

```php
$v = new Validator(['password' => 'x']);
$v->required('password')
  ->minLength('password', 8)
  ->regex('password', '/[A-Z]/', 'Password must contain an uppercase letter.');

// $v->errors()['password'] may contain up to 2 messages:
// - "The password field must be at least 8 characters."
// - "Password must contain an uppercase letter."
```

## Combining with VALIDATION-FAILED

The `VALIDATION-FAILED` error code (HTTP 422) is the canonical code for request validation failures. See `docs/development/error-codes.md` for the full catalog.

```php
if (!$v->passes()) {
    $this->API_RESPONSE->set('validationErrors', $v->errors());
    $this->API_RESPONSE->failure('VALIDATION-FAILED');
    return;
}
```

## Related

- `class/func/Validator.php` — implementation
- `config/error_codes.php` — `VALIDATION-FAILED` entry
- `docs/development/error-codes.md` — error catalog
- `DataMapperBase::isValid()` — entity-level schema validation (see `class/db/`)
