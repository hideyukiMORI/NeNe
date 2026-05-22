# Security Headers Self-Review

Use this checklist whenever a PR changes `class/xion/ResponseDecorator.php`, `compose.yaml`'s `NENE_SECURITY_*` env vars, `class/xion/HttpEmitter.php`, `class/xion/View.php::execute`, or any controller that calls `header('Content-Security-Policy' / 'Strict-Transport-Security' / 'X-Frame-Options' / 'Referrer-Policy' / 'Permissions-Policy' / 'X-Content-Type-Options' ...)`.

Source policies:

- `docs/development/security-headers.md` (env matrix, cookbook values, two-hook design)
- `docs/adr/0007-response-decoration-boundary.md`
- `docs/development/production-deployment.md` (env-var matrix incl. all five `NENE_SECURITY_*`)

## Wiring checklist

- [ ] The change does not introduce a **third** integration point. If decoration needs to apply somewhere new (e.g., a custom direct-`echo` path), prefer routing through `HttpEmitter::emit()` first.
- [ ] If the change adds a new header to `ResponseDecorator::ALWAYS_ON`, the value is **zero-cost / universal** (like `nosniff`). If it could break a deploy, it should be env-opt-in instead.
- [ ] If the change adds a new env var for a header, the name follows the `NENE_SECURITY_*` pattern, and the var is added to `compose.yaml` (empty default) **and** to the `docs/development/production-deployment.md` env-var matrix.
- [ ] `ResponseDecorator::reset()` is called in the corresponding unit test's `setUp` / `tearDown` to avoid cache leakage across cases.

## Controller-override checklist

- [ ] If a controller intentionally overrides a decorator header, the override is documented in the controller's PHPDoc with the reason. (Silent overrides are a footgun.)
- [ ] The override uses standard PHP `header(...)` and is set **before** any output begins (echo / Smarty display).
- [ ] If the override is the same value as the decorator (no real difference), remove the override — the decorator already covers it.

## CSP-specific checklist

- [ ] The CSP value has been smoke-tested against the deploy's actual pages, including Smarty templates, Swagger UI (`/api-docs/`), and any inline `<style>` / `<script>` introduced by `view/source/layout/app.tpl` or asset auto-discovery (`htdocs/css/...`, `htdocs/js/...`).
- [ ] If the policy requires `'unsafe-inline'` or `'unsafe-eval'`, the PR body justifies it (Swagger UI may need `style-src 'unsafe-inline'`; everything else should be tightened).
- [ ] Frame-ancestors / frame-src are aligned with `NENE_SECURITY_FRAME_OPTIONS` (browsers prefer CSP `frame-ancestors` over the deprecated `X-Frame-Options` when both are set).

## HSTS-specific checklist

- [ ] `NENE_SECURITY_HSTS` is **only** set when the deploy has HTTPS termination verified end-to-end. Setting HSTS over plain HTTP locks browsers into a broken state.
- [ ] `max-age` is at least one year (`31536000`) for production. Shorter values are fine during initial HTTPS rollout.
- [ ] `includeSubDomains` is set only if every subdomain of the deploy can serve HTTPS.
- [ ] `preload` (in the header value) is **not** added without a separate decision — preload-list inclusion is hard to undo.

## Operator checklist

- [ ] The `compose.prod.yaml` overlay (or equivalent production config) sets every `NENE_SECURITY_*` env var the deploy needs. Empty defaults are not "off by accident".
- [ ] Reverse-proxy / CDN does not strip the headers added by NeNe. Verify with `curl -i` against the actual production hostname.
- [ ] If Apache directly serves static files (CSS, JS, images, favicon), those files have their own header set in `docker/apache/conf-available/zz-nene-static-headers.conf` (or equivalent). The `ResponseDecorator` does not reach static files.

## Test plan checklist

- [ ] Unit: every code path through `ResponseDecorator::decorate` and `::sendHeaders` is exercised in `tests/Unit/Xion/ResponseDecoratorTest.php`.
- [ ] HTTP smoke: the relevant new endpoint (if any) carries the expected headers per `composer test:http`.
- [ ] Live: `curl -sS -i http://localhost:8080/health` shows the configured headers.
