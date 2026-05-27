<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Func;

/**
 * Lightweight JSON Schema-subset validator for decoded request bodies.
 *
 * Validates a PHP value (typically the result of `json_decode($body, true)`)
 * against a schema definition. Error messages use JSON Pointer paths
 * (e.g. `/address/city`, `/items/0/price`) to identify the failing location.
 *
 * ## Supported keywords
 *
 * | Keyword | Types | Description |
 * |---------|-------|-------------|
 * | `type` | all | `'string'`, `'integer'`, `'number'`, `'boolean'`, `'array'`, `'object'`, `'null'` |
 * | `nullable` | all | `true` allows `null` in addition to the declared type |
 * | `required` | object | List of required property names |
 * | `properties` | object | Sub-schemas keyed by property name |
 * | `minLength` | string | Minimum character count (mb_strlen) |
 * | `maxLength` | string | Maximum character count (mb_strlen) |
 * | `pattern` | string | PCRE pattern the value must match |
 * | `minimum` | integer/number | Inclusive lower bound |
 * | `maximum` | integer/number | Inclusive upper bound |
 * | `enum` | all | Value must be in the given list (strict comparison) |
 * | `items` | array | Sub-schema applied to every element |
 *
 * ## Basic usage
 *
 * ```php
 * $schema = [
 *     'type'       => 'object',
 *     'required'   => ['name', 'age'],
 *     'properties' => [
 *         'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
 *         'age'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150],
 *         'role' => ['type' => 'string', 'enum' => ['admin', 'user', 'guest']],
 *     ],
 * ];
 *
 * $body = json_decode($request->body(), true);
 * $errors = JsonSchemaValidator::validate($body, $schema);
 *
 * if ($errors !== []) {
 *     // respond 422 with error list
 * }
 * ```
 *
 * ## Notes
 *
 * - PHP arrays serve as both JSON objects (string-keyed) and JSON arrays
 *   (integer-keyed). Use `type: 'object'` for JSON objects and `type: 'array'`
 *   for JSON arrays; both accept PHP arrays.
 * - Unknown keywords are silently ignored, making forward compatibility easy.
 * - The `$pointer` parameter in `validate()` is used internally for recursion;
 *   callers should omit it.
 */
final class JsonSchemaValidator
{
    /**
     * Validate $data against $schema and return a list of error messages.
     *
     * Returns an empty array when validation passes.
     *
     * @param  mixed                $data    The value to validate.
     * @param  array<string, mixed> $schema  Schema definition (JSON Schema subset).
     * @param  string               $pointer JSON Pointer prefix for error paths (internal use).
     * @return list<string>                  Validation error messages; empty when valid.
     */
    public static function validate(mixed $data, array $schema, string $pointer = ''): array
    {
        $errors = [];

        // null + nullable: bypass all further checks
        if ($data === null && ($schema['nullable'] ?? false)) {
            return [];
        }

        // Type check
        if (isset($schema['type'])) {
            $typeError = self::checkType($data, $schema['type'], $pointer);
            if ($typeError !== null) {
                return [$typeError]; // type mismatch blocks all further checks
            }
        }

        // String constraints
        if (is_string($data)) {
            $len = mb_strlen($data);
            if (isset($schema['minLength']) && $len < $schema['minLength']) {
                $errors[] = self::path($pointer) . " must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && $len > $schema['maxLength']) {
                $errors[] = self::path($pointer) . " must be at most {$schema['maxLength']} characters";
            }
            if (isset($schema['pattern']) && !preg_match($schema['pattern'], $data)) {
                $errors[] = self::path($pointer) . " does not match required pattern";
            }
        }

        // Numeric constraints
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = self::path($pointer) . " must be at least {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = self::path($pointer) . " must be at most {$schema['maximum']}";
            }
        }

        // Enum
        if (isset($schema['enum'])) {
            if (!in_array($data, $schema['enum'], true)) {
                $allowed = implode(', ', array_map(
                    static fn (mixed $v): string => json_encode($v) ?: '',
                    $schema['enum'],
                ));
                $errors[] = self::path($pointer) . " must be one of: {$allowed}";
            }
        }

        // Object constraints (properties + required)
        if (is_array($data) && (isset($schema['properties']) || isset($schema['required']))) {
            foreach ($schema['required'] ?? [] as $field) {
                if (!array_key_exists($field, $data)) {
                    $errors[] = self::join($pointer, (string) $field) . ' is required';
                }
            }
            foreach ($schema['properties'] ?? [] as $field => $subSchema) {
                if (array_key_exists($field, $data)) {
                    $sub = self::validate($data[$field], $subSchema, self::join($pointer, (string) $field));
                    $errors = array_merge($errors, $sub);
                }
            }
        }

        // Array items
        if (is_array($data) && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $sub = self::validate($item, $schema['items'], self::join($pointer, (string) $i));
                $errors = array_merge($errors, $sub);
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return a type-mismatch error string, or null if the value matches.
     */
    private static function checkType(mixed $data, string $type, string $pointer): ?string
    {
        $ok = match ($type) {
            'string'  => is_string($data),
            'integer' => is_int($data),
            'number'  => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'array'   => is_array($data),
            'object'  => is_array($data),
            'null'    => $data === null,
            default   => true,
        };
        return $ok ? null : self::path($pointer) . " must be of type {$type}";
    }

    /**
     * Produce a human-readable path label from a JSON Pointer string.
     * Empty pointer → `'/'`; `/foo/bar` → `'/foo/bar'`.
     */
    private static function path(string $pointer): string
    {
        return $pointer === '' ? '/' : $pointer;
    }

    /**
     * Append a segment to a JSON Pointer string.
     * `/foo` + `bar` → `/foo/bar`.  `` + `name` → `/name`.
     */
    private static function join(string $pointer, string $segment): string
    {
        return $pointer . '/' . $segment;
    }
}
