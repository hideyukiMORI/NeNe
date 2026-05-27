<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Fluent input validator for HTTP request parameters.
 *
 * Complements DataMapperBase::isValid() (entity schema validation) with
 * request-level validation that produces structured error messages.
 *
 * Usage in a controller:
 *
 *   $v = new Validator($this->POST->getArray(['title', 'email', 'age']));
 *   $v->required('title', 'email')
 *     ->maxLength('title', 200)
 *     ->email('email')
 *     ->integer('age', min: 0, max: 150);
 *
 *   if (!$v->passes()) {
 *       return $this->API_RESPONSE->failure('VALIDATION-FAILED');
 *       // or: echo json_encode($v->errors()); exit;
 *   }
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $input The input array to validate (e.g. from $_POST or parsed JSON).
     */
    public function __construct(private readonly array $input)
    {
    }

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    /**
     * Require that the field is present and not empty ('', null, []).
     */
    public function required(string ...$fields): static
    {
        foreach ($fields as $field) {
            $value = $this->input[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $this->addError($field, "The {$field} field is required.");
            }
        }
        return $this;
    }

    /**
     * Require that the string value does not exceed a maximum length.
     *
     * Skips if the field is absent (combine with required() for presence checks).
     */
    public function maxLength(string $field, int $max): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (mb_strlen((string) $value) > $max) {
            $this->addError($field, "The {$field} field must not exceed {$max} characters.");
        }
        return $this;
    }

    /**
     * Require that the string value meets a minimum length.
     */
    public function minLength(string $field, int $min): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (mb_strlen((string) $value) < $min) {
            $this->addError($field, "The {$field} field must be at least {$min} characters.");
        }
        return $this;
    }

    /**
     * Require that the value is a valid email address.
     */
    public function email(string $field): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, "The {$field} field must be a valid email address.");
        }
        return $this;
    }

    /**
     * Require that the value is a valid URL.
     */
    public function url(string $field): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
            $this->addError($field, "The {$field} field must be a valid URL.");
        }
        return $this;
    }

    /**
     * Require that the value is an integer within an optional range.
     */
    public function integer(string $field, ?int $min = null, ?int $max = null): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, "The {$field} field must be an integer.");
            return $this;
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            $this->addError($field, "The {$field} field must be at least {$min}.");
        }
        if ($max !== null && $int > $max) {
            $this->addError($field, "The {$field} field must be at most {$max}.");
        }
        return $this;
    }

    /**
     * Require that the value is one of a given set of allowed values.
     */
    public function in(string $field, array $allowed): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (!in_array($value, $allowed, strict: true)) {
            $list = implode(', ', array_map(fn ($v) => (string) $v, $allowed));
            $this->addError($field, "The {$field} field must be one of: {$list}.");
        }
        return $this;
    }

    /**
     * Require that the value matches a regular expression.
     */
    public function regex(string $field, string $pattern, string $message = ''): static
    {
        $value = $this->input[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        if (!preg_match($pattern, (string) $value)) {
            $this->addError($field, $message !== '' ? $message : "The {$field} field has an invalid format.");
        }
        return $this;
    }

    // -----------------------------------------------------------------------
    // Result
    // -----------------------------------------------------------------------

    /**
     * Return true if no validation errors were recorded.
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * Return all recorded errors.
     *
     * @return array<string, list<string>> Field → list of error messages.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Return the first error message for each field.
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $result = [];
        foreach ($this->errors as $field => $messages) {
            $result[$field] = $messages[0];
        }
        return $result;
    }

    // -----------------------------------------------------------------------

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
