# CORS — Cross-Origin Resource Sharing

This guide covers `Nene\Xion\Cors`, the utility class added in FT45 for emitting CORS response headers and handling OPTIONS preflight requests.

For the relationship between CORS and CSRF protection, see `docs/development/cors-and-csrf.md`.

## What CORS is and when you need it

CORS is a browser security mechanism that controls whether JavaScript running on one origin (scheme + host + port) can read a response that came from a different origin.

You need CORS when:

- Your NeNe REST API is hosted at `https://api.example.com`
- A browser SPA is hosted at `https://app.example.com`
- The SPA makes `fetch()` or `XMLHttpRequest` calls to the API

Without CORS headers on the API, the browser will block the SPA from reading the response. The request still reaches the server — CORS is enforced by the browser, not the server — but the JavaScript cannot access the result.

Non-browser callers (curl, Postman, server-to-server) are unaffected by CORS headers.

## Usage in preAction()

The most common pattern is to call `Cors::sendHeaders()` and then `Cors::handlePreflight()` from `ControllerBase::preAction()`:

```php
use Nene\Xion\Cors;

final class ApiController extends ControllerBase
{
    protected function preAction(): void
    {
        Cors::sendHeaders(
            allowedOrigins: ['https://app.example.com', 'https://admin.example.com'],
            allowedMethods: ['GET', 'POST', 'PUT', 'DELETE'],
            allowedHeaders: ['Content-Type', 'X-CSRF-Token'],
            credentials:    true,
        );
        Cors::handlePreflight();  // exits with 204 on OPTIONS; no-op otherwise
    }
}
```

`preAction()` runs before every action in the controller, so all endpoints in that controller automatically receive the CORS headers.

## Origin matching

### Wildcard

Pass `['*']` to allow any origin:

```php
Cors::sendHeaders(allowedOrigins: ['*']);
```

The response will include `Access-Control-Allow-Origin: *`. This is appropriate for fully public APIs with no authentication. **Wildcard is incompatible with `credentials: true`** — see the section below.

### Explicit allow-list

Pass a list of specific origins. The `Origin` request header is matched case-insensitively. If the incoming origin is in the list, it is reflected back in `Access-Control-Allow-Origin` (required for credentials). If not matched, no CORS headers are sent at all.

```php
Cors::sendHeaders(
    allowedOrigins: [
        'https://app.example.com',
        'https://admin.example.com',
    ],
    origin: $request->origin(),   // or let it read $_SERVER['HTTP_ORIGIN']
);
```

A `Vary: Origin` header is also emitted when using the explicit list, so CDN caches serve the correct variant per caller origin.

### isAllowed() helper

You can check origin membership independently of sending headers:

```php
if (Cors::isAllowed($_SERVER['HTTP_ORIGIN'] ?? '', $allowedOrigins)) {
    // origin is allowed
}
```

This is useful in middleware that needs to gate logic before headers are emitted.

## Credential and cookie flows

When your SPA sends session cookies or `Authorization` headers cross-origin, both sides must opt in:

**Server side** — pass `credentials: true` to `sendHeaders()`:

```php
Cors::sendHeaders(
    allowedOrigins: ['https://app.example.com'],
    credentials:    true,
);
```

This adds `Access-Control-Allow-Credentials: true` to the response.

**Browser side** — the `fetch()` call must include `credentials: 'include'`:

```js
fetch('https://api.example.com/items', { credentials: 'include' });
```

### Why wildcard is incompatible with credentials

When `credentials: true`, the browser requires `Access-Control-Allow-Origin` to be an explicit origin, not `*`. Browsers will reject `Access-Control-Allow-Origin: *` combined with `Access-Control-Allow-Credentials: true`.

**Do not** combine `allowedOrigins: ['*']` with `credentials: true`. `sendHeaders()` does not enforce this — the combination will simply result in the browser rejecting the response.

## Preflight (OPTIONS) handling

When a browser makes a cross-origin request with a non-simple method (`PUT`, `DELETE`, `PATCH`) or with custom headers (e.g. `Content-Type: application/json`, `X-CSRF-Token`), it first sends an OPTIONS preflight to ask the server what it allows.

`Cors::handlePreflight()` handles this:

```php
Cors::sendHeaders(/* ... */);
Cors::handlePreflight();  // if OPTIONS: emits 204, exits; otherwise no-op
```

Call `sendHeaders()` first so the CORS headers are queued before `handlePreflight()` exits.

If the current method is not OPTIONS, `handlePreflight()` returns normally and the request proceeds through the normal action dispatch.

## Expose-headers for custom response headers

By default, browsers only expose a small set of response headers to JavaScript (`Cache-Control`, `Content-Language`, `Content-Type`, `Expires`, `Last-Modified`, `Pragma`). To make custom headers readable:

```php
Cors::sendHeaders(
    allowedOrigins:  ['https://app.example.com'],
    exposeHeaders:   ['X-Request-Id', 'X-Rate-Limit-Remaining', 'X-Rate-Limit-Reset'],
);
```

This adds `Access-Control-Expose-Headers: X-Request-Id, X-Rate-Limit-Remaining, X-Rate-Limit-Reset` so the SPA can read those values from the response.

## Preflight cache (maxAge)

The `maxAge` parameter controls how many seconds the browser may cache the preflight result (default: 86400 = 24 hours). During the cache window, browsers skip the OPTIONS request for identical method+headers combinations.

```php
Cors::sendHeaders(
    allowedOrigins: ['https://app.example.com'],
    maxAge:         3600,   // 1 hour
);
```

## Security notes

- **Always use explicit origin lists in production.** `allowedOrigins: ['*']` is only appropriate for fully public, read-only, unauthenticated APIs. Any API that uses session cookies, JWT tokens, or returns user-specific data should enumerate the allowed origins.
- **CORS is not a substitute for authentication or authorisation.** It controls what browsers can read; it does not prevent server-side execution of the request.
- **CORS does not prevent CSRF.** A cross-origin request still arrives at your server. Use NeNe's `X-CSRF-Token` mechanism for browser-session endpoints. See `docs/development/cors-and-csrf.md`.
- **Validate the Origin header server-side if you gate logic on it.** `isAllowed()` is case-insensitive and does exact-string matching; it does not perform DNS resolution or subdomain wildcard matching.

## Related

- `class/xion/Cors.php` — implementation
- `docs/development/cors-and-csrf.md` — CORS vs CSRF distinction
- `docs/development/security-headers.md` — other response security headers
- `class/xion/CsrfProtectionPolicy.php` — CSRF token enforcement
- `class/xion/ControllerBase.php` — preAction() extension point
