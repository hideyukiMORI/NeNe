# Security Headers

How NeNe adds cross-cutting response headers (security or otherwise) to every PHP-generated response. Pair with ADR-0007 (`docs/adr/0007-response-decoration-boundary.md`).

Audience: anyone hardening a production deployment or wiring an observability concern that needs a header on every response. Trial source: FT14 (`docs/field-trials/2026-05-field-trial-14.md`). Predicted by FT7 F-6 + FT8 F-4 and resolved by FT14.

## The pieces

| What | Where |
| --- | --- |
| Decoration class | `Nene\Xion\ResponseDecorator` |
| HttpEmitter hook | `HttpEmitter::emit()` calls `ResponseDecorator::decorate()` |
| Smarty hook | `View::execute()` calls `ResponseDecorator::sendHeaders()` |
| Env vars | `NENE_SECURITY_CSP`, `NENE_SECURITY_FRAME_OPTIONS`, `NENE_SECURITY_REFERRER_POLICY`, `NENE_SECURITY_HSTS`, `NENE_SECURITY_PERMISSIONS_POLICY` |
| Always-on | `X-Content-Type-Options: nosniff` (no env var) |

## Header set

| Header | Default | Env var | Recommended starting value |
| --- | --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` (always-on) | — | — |
| `Content-Security-Policy` | unset | `NENE_SECURITY_CSP` | `default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'` |
| `X-Frame-Options` | unset | `NENE_SECURITY_FRAME_OPTIONS` | `DENY` (or `SAMEORIGIN` if the app needs same-origin embedding) |
| `Referrer-Policy` | unset | `NENE_SECURITY_REFERRER_POLICY` | `strict-origin-when-cross-origin` |
| `Strict-Transport-Security` | unset | `NENE_SECURITY_HSTS` | `max-age=31536000; includeSubDomains` (HTTPS terminator必須) |
| `Permissions-Policy` | unset | `NENE_SECURITY_PERMISSIONS_POLICY` | `geolocation=(), microphone=(), camera=()` (deny by default; expand as features require) |

Set values in `compose.prod.yaml` (or the equivalent overlay), one env var per header. NeNe ships **no** opinionated value — every CSP that ships is wrong for some deploy.

## How decoration reaches every response

`ResponseDecorator::decorate()` runs at the framework's output boundary in **two** places:

1. **`HttpEmitter::emit()`** — catches every response that flows through `HttpTermination` (auth redirect, CSRF reject, 401 / 403 / 404 / 405 / 500), every `DomainException`, every top-level `\Throwable`, every REST 2xx, every `sendFile()` binary download (FT12 #368).
2. **`View::execute()`** — Smarty's `display()` streams template output directly to stdout, bypassing `HttpEmitter`. The decorator's `sendHeaders()` runs **before** Smarty echoes so the headers go out first.

That covers every PHP-generated response. The FT7 F-6 / FT8 F-4 trap (decoration added at `ControllerBase::run()`'s tail silently skips error paths) is closed: the decoration is at the lower boundary, not the controller layer.

## Controller-set headers win

When a controller writes its own header before responding, the decorator does **not** overwrite it. Match is case-insensitive — `'x-frame-options'` from the controller still beats `'X-Frame-Options'` from the decorator. Use this when one endpoint needs a different value than the deploy-wide default (e.g., an admin iframe page that needs `X-Frame-Options: SAMEORIGIN` while the rest of the app uses `DENY`).

```php
public function indexAction(): void
{
    // Just before render, override the decorator's X-Frame-Options:
    header('X-Frame-Options: SAMEORIGIN');
    $this->setTitle('Admin');
}
```

## Apache-served static files

The decorator only covers **PHP-generated** responses. Apache directly serves `htdocs/favicon.ico` and anything else under the document root without invoking PHP, so those responses do not carry the decorator's headers.

If a production deploy needs CSP on static files too, add an Apache directive (the same pattern FT8 #330 used for `ServerTokens`):

```apache
# docker/apache/conf-available/zz-nene-static-headers.conf
<FilesMatch "\.(ico|css|js|png|jpg|svg|webp)$">
    Header set X-Content-Type-Options "nosniff"
    Header set Cache-Control "public, max-age=86400"
</FilesMatch>
```

then `a2enconf` it in `Dockerfile`. The `zz-` prefix is intentional (FT8 trap — must load after `security.conf`).

## Testing

```php
use Nene\Xion\ResponseDecorator;

protected function setUp(): void
{
    putenv('NENE_SECURITY_CSP');               // clear
    ResponseDecorator::reset();
}

public function testFoo(): void
{
    putenv("NENE_SECURITY_CSP=default-src 'self'");
    ResponseDecorator::reset();                  // rebuild from current env
    // assertions ...
}
```

`tests/Unit/Xion/ResponseDecoratorTest.php` shows the seven canonical cases (default, env opt-in, controller wins, case-insensitive match, cache + reset semantics).

## Production deployment checklist

- [ ] `NENE_SECURITY_CSP` is set (start with the value above; loosen per page if needed).
- [ ] `NENE_SECURITY_HSTS` is set **only after** HTTPS termination is verified end-to-end.
- [ ] `NENE_SECURITY_FRAME_OPTIONS` is set (`DENY` unless the app needs same-origin embedding).
- [ ] `NENE_SECURITY_REFERRER_POLICY` is set.
- [ ] Swagger UI (`http://localhost:8080/api-docs/` in dev) renders without CSP errors. (Swagger UI inlines styles/scripts; you may need `'unsafe-inline'` for `style-src`.)
- [ ] Smarty pages render — check `docs/frontend/assets.md`'s `t_css` and `t_js` patterns work under the CSP.
- [ ] No controller silently overrides a critical header. Grep for `header('X-Frame|Content-Security|Strict-Transport|Referrer-Policy|Permissions-Policy'`.

## Related

- `docs/adr/0007-response-decoration-boundary.md` — design rationale.
- `docs/development/production-deployment.md` — full env-var matrix (the five `NENE_SECURITY_*` variables are listed).
- `docs/development/error-codes.md` § "Response decoration and the error-path early-exit trap" — historical predictions (FT7 F-6 + FT8 F-4) now marked as resolved.
- `docs/review/security-headers.md` — reviewer checklist.
- `docs/field-trials/2026-05-field-trial-14.md` — the implementation trial.
- `class/xion/ResponseDecorator.php` — the class.
- MDN: [Content-Security-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) / [HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Strict-Transport-Security) / [Permissions-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Permissions-Policy).
