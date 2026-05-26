# JSON-Only API Behavior

NeNe is a JSON-first framework. This page documents the concrete behavior of NeNe's HTTP layer regarding content negotiation, request bodies, and response types — so developers know what to expect without guessing.

## Response: always JSON (no content negotiation)

NeNe does not implement `Accept` header content negotiation. All responses are always:

```
Content-Type: application/json; charset=utf-8
```

Regardless of what `Accept` the client sends:

| Client `Accept` header | Response `Content-Type` |
|---|---|
| (none) | `application/json; charset=utf-8` |
| `application/json` | `application/json; charset=utf-8` |
| `*/*` | `application/json; charset=utf-8` |
| `text/html` | `application/json; charset=utf-8` |
| `application/xml` | `application/json; charset=utf-8` |

NeNe does **not** return `406 Not Acceptable` — even if the client's `Accept` list has no overlap with `application/json`. This is a deliberate design choice for simplicity: NeNe serves JSON and only JSON.

## Request body: JSON required for POST/PUT/PATCH

NeNe reads request bodies using `JsonResponder`, which calls `json_decode()` on the raw request body. It does **not** check the inbound `Content-Type` header — it tries to parse as JSON unconditionally.

| Request scenario | Result |
|---|---|
| `Content-Type: application/json` + valid JSON body | ✅ 200 / parsed correctly |
| No `Content-Type` header + valid JSON body | ✅ parsed correctly (liberal input) |
| `Content-Type: application/x-www-form-urlencoded` + form body | ❌ 400 (JSON parse failure) |
| `Content-Type: application/json` + empty body | ⚠️ depends — `null` from `json_decode('')` |
| Empty body, no content-type | ⚠️ `json_decode('')` returns `null` |

**Practical rules for API clients:**
- Always send `Content-Type: application/json` (habit/documentation value — NeNe does not enforce it, but clients should).
- Send a valid JSON object `{}` for requests with no fields (not an empty body, and not `[]`).

## `json_encode([])` pitfall for tests

In PHP, `json_encode([])` produces `"[]"` (a JSON array), not `"{}"` (a JSON object). NeNe's controller code typically reads `$this->REQUEST_JSON['field']` expecting an object; receiving an array causes unexpected behavior.

**In tests, never use an empty PHP array as a "no body" substitute:**

```php
// WRONG — sends JSON array "[]", not empty object
$response = $this->client->json('POST', '/booking/index', []);

// RIGHT — send an empty JSON object
$response = $this->client->json('POST', '/booking/index', new \stdClass());
// Or: send an object with unrelated fields if the endpoint requires something
$response = $this->client->json('POST', '/booking/index', ['_dummy' => true]);
```

When testing "missing required field" validation, send a JSON object that omits the required field — not an empty body:

```php
// Testing that missing 'name' → 422
$response = $this->client->json('POST', '/item/index', ['description' => 'test']); // no 'name' key
```

## Non-ASCII characters in responses

NeNe's `JsonResponder::encode()` uses `JSON_UNESCAPED_UNICODE`, so Japanese, Chinese, emoji, and other non-ASCII characters appear as-is in JSON responses:

```json
{ "name": "田中 太郎", "emoji": "🎉" }
```

Not as escaped sequences:

```json
{ "name": "田中 太郎", "emoji": "🎉" }
```

> **Exception:** `View::encodeScriptJson()` — used for JSON embedded in HTML `<script>` tags — intentionally keeps `JSON_HEX_TAG` and similar flags and does **not** apply `JSON_UNESCAPED_UNICODE`. HTML context requires XSS-safe escaping.

## 405 Method Not Allowed

When a route path exists but the HTTP method is not registered, NeNe returns 405. When neither path nor method matches, it returns 404.

```
GET /todo/index     → 200 (route exists, correct method)
DELETE /todo/index  → 405 (route exists, wrong method)
GET /nonexistent    → 404 (no route matches)
```

## Related

- `docs/development/cors-and-csrf.md` — CORS headers and CSRF protection
- `docs/development/security-headers.md` — response security headers
- `docs/development/unicode-validation.md` — `mb_strlen` and character encoding
