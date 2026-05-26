# Unicode and Encoding Validation

NeNe applications often handle multilingual input — Japanese, Arabic, emoji, and other non-ASCII text. This guide covers the common pitfalls.

## JSON responses: non-ASCII is NOT escaped

`JsonResponder` includes `JSON_UNESCAPED_UNICODE` so Japanese and emoji are returned as-is:

```json
{"title": "ホーム"}       // ✅ readable
{"title": "ホーム"}  // ❌ old behavior
```

> **Note for `<script>` context:** `View::encodeScriptJson()` intentionally does NOT use `JSON_UNESCAPED_UNICODE` because it outputs JSON inside an HTML `<script>` tag where unescaped line terminators (U+2028, U+2029) could break the script block. Use `json_encode` with the `JSON_HEX_*` flags for that context.

## String length: use `mb_strlen`, not `strlen`

`strlen` counts **bytes**, not characters. For multibyte text the byte count is wrong:

```php
strlen('あ')             // 3 (UTF-8 bytes)
mb_strlen('あ', 'UTF-8') // 1 (character)

strlen('Hello 👋')       // 10 (6 + 4 bytes for the emoji)
mb_strlen('Hello 👋', 'UTF-8') // 7 (characters)
```

Always validate length with `mb_strlen`:

```php
$title = trim((string)($this->REQUEST_JSON['title'] ?? ''));
if (mb_strlen($title, 'UTF-8') > 255) {
    return $this->API_RESPONSE->failure('TITLE-TOO-LONG');
}
```

### Grapheme clusters and emoji sequences

`mb_strlen` counts Unicode codepoints, not grapheme clusters (rendered glyphs). A family emoji `👨‍👩‍👧` is one visible glyph but 5 codepoints (joined by U+200D Zero Width Joiner). `mb_strlen` returns 5, not 1.

For a "50 characters as displayed" limit, use `grapheme_strlen()` from the `intl` extension:

```php
if (grapheme_strlen($title) > 50) {
    return $this->API_RESPONSE->failure('TITLE-TOO-LONG');
}
```

For most practical applications `mb_strlen` is sufficient — users understand that multi-codepoint emoji "use more characters." Only use `grapheme_strlen` when the user-visible count must be exact.

## Null bytes

SQLite (and MySQL) TEXT columns accept null bytes. A value like `"Alice\x00Bob"` is stored and retrieved without error. PHP itself is null-byte safe in modern versions, but some library functions (file path operations, certain C extensions) treat `\x00` as a string terminator.

Reject null bytes explicitly for any user-supplied string:

```php
$value = (string)($this->REQUEST_JSON['name'] ?? '');
if (str_contains($value, "\x00")) {
    return $this->API_RESPONSE->failure('INVALID-INPUT');
}
```

Alternatively, strip null bytes silently if your use case treats them as noise:

```php
$value = str_replace("\x00", '', $value);
```

## LIKE search with non-ASCII

LIKE pattern matching is case-insensitive for ASCII but case-sensitive for non-ASCII in most MySQL collations. For Japanese, MySQL's `utf8mb4_unicode_ci` collation handles case folding for Latin characters; Hiragana/Katakana are treated as distinct.

For a multilingual search box, test with the actual target collation (`utf8mb4_unicode_ci` is the NeNe default).

## Common validation snippet

A reusable pattern for validated string inputs:

```php
/**
 * Validate and clean a user-supplied UTF-8 string.
 *
 * @return string|null Cleaned string, or null if validation fails.
 */
private function validateString(mixed $raw, int $maxChars): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if ($value === '' || str_contains($value, "\x00")) {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maxChars) {
        return null;
    }
    return $value;
}
```

## Related

- `docs/development/sql-injection.md` — SQL injection defense including LIKE wildcard escaping
- `docs/tutorials/building-a-service.md` — controller and mapper patterns
