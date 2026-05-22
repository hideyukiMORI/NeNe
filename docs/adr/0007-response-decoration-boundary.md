# ADR 0007: Response Decoration at the HttpEmitter Boundary

## Status

Accepted

## Context

Field Trials 7 and 8 both surfaced the same structural problem: NeNe has no place to add cross-cutting concerns to every HTTP response. FT7 finding F-6 (`docs/development/error-codes.md` § "Response decoration and the error-path early-exit trap") documented it; FT8 finding F-4 (#331) showed it bite a second time when pre-dispatch 404 / 405 responses skipped the access log. FT8 patched the symptom (the dispatcher now emits its own access entries before throwing) but the framework still has no general boundary.

The trigger that forced a real decision is **security headers**: a production deployment wants `X-Content-Type-Options: nosniff` on every response, opt-in `Content-Security-Policy` / `Strict-Transport-Security` / `X-Frame-Options` / `Referrer-Policy` / `Permissions-Policy` on every response — including the error paths that bypass `ControllerBase::run()`. Any future cross-cutting concern (request IDs / correlation IDs, `Server-Timing`, OpenTelemetry headers, audit fingerprints) will need the same shape.

Four placements were considered:

| Placement | Coverage | Trade-off |
| --- | --- | --- |
| `ControllerBase::run()` tail | only 2xx success paths | **The FT7 F-6 trap.** `HttpTermination` / `DomainException` / `\Throwable` paths skip it. Repeated finding. |
| Apache `Header set ...` in conf-available | static + dynamic responses | Env-driven config is awkward (FT8 #330's `zz-` order trap repeats); no per-controller override path. |
| Middleware framework around dispatch | maximally flexible | NeNe philosophy is a small framework. Adding a middleware abstraction expands the API surface for one concern. |
| `Nene\Xion\HttpEmitter::emit()` | every PHP-generated response *except* the Smarty success path | One small chokepoint. Existing test coverage of `HttpEmitter` already exercises it. The Smarty path needs a sibling hook. |

Tracing every response shows `HttpEmitter::emit()` handles:

- REST success — `JsonResponder::outputArray()` throws `HttpTermination` → `htdocs/index.php` catches → `HttpEmitter::emit()`.
- `HttpTermination` from auth redirect, CSRF reject, 405, 404, file download (`sendFile`).
- `DomainException` catch in `htdocs/index.php`.
- Top-level `\Throwable` catch in `htdocs/index.php` (the framework's last-resort 500).

Only one response path bypasses `HttpEmitter`: `View::execute()` calls `Smarty::display()` which writes directly to stdout. Routing Smarty output through `HttpEmitter` would require buffering the entire rendered template into a string — doubling memory cost for large pages with no other benefit. So the trial accepts a **two-hook shape**: `HttpEmitter::emit()` for everything that flows through it, and a sibling call in `View::execute()` that emits the same headers via PHP's `header()` before Smarty's stream begins.

The set of "every-response" headers is intentionally tiny in this ADR. Only `X-Content-Type-Options: nosniff` is always-on (zero-cost protection against MIME sniffing). Everything else (CSP / HSTS / X-Frame-Options / Referrer-Policy / Permissions-Policy) is opt-in via env, because the right value is deployment-specific (a strict CSP breaks Swagger UI; HSTS without HTTPS is wrong; Frame-Options choice depends on whether the app is embedded). The framework's job is the wiring; the values are the operator's call.

## Decision

- Introduce `Nene\Xion\ResponseDecorator`, a pure-static class that exposes:
  - `headers(): array<string,string>` — the set of cross-cutting headers, lazily computed from the environment and cached.
  - `decorate(HttpResponse $response): HttpResponse` — return a new `HttpResponse` carrying every decorator header the caller did not already set. Case-insensitive comparison so a controller writing `'x-frame-options'` lowercase still wins.
  - `sendHeaders(): void` — emit the decorator headers via PHP's `header()` for paths that do not flow through `HttpEmitter`. Skips headers PHP has already emitted (`headers_list`).
  - `reset(): void` — drop the cache so the next call rebuilds from env (used by tests).
- Modify `Nene\Xion\HttpEmitter::emit()` to call `ResponseDecorator::decorate()` before sending. One-line wrap.
- Modify `Nene\Xion\View::execute()` to call `ResponseDecorator::sendHeaders()` before `Smarty::display()`. One-line addition.
- Always emit `X-Content-Type-Options: nosniff`. Make every other header opt-in via `NENE_SECURITY_*` env vars (CSP / FRAME_OPTIONS / REFERRER_POLICY / HSTS / PERMISSIONS_POLICY).
- `compose.yaml` passes the five env vars through (default empty).
- Document the boundary in `docs/development/security-headers.md` and the env matrix in `docs/development/production-deployment.md`.

The choice is **not** prescriptive about future concerns. A future ADR may add a third hook (e.g., for `Server-Timing` that requires per-request timing data), extend `ResponseDecorator` with a non-static decoration list, or even introduce a tiny middleware-like construct. This ADR records the **first** cross-cutting concern boundary and the reasoning for placing it at `HttpEmitter` + `View::execute()`.

## Consequences

Positive:

- The FT7 F-6 / FT8 F-4 structural trap is resolved. Every PHP-generated response now picks up cross-cutting decoration regardless of path.
- Operators ship security headers by setting env vars on the production container. No framework code change per deploy.
- Future cross-cutting concerns plug into the same class. The shape is reusable.
- `ResponseDecorator` is pure-static and reset-friendly. Unit tests inject env via `putenv()` and call `reset()` between assertions.

Negative / accepted trade-offs:

- Two integration points instead of one. The HTML success path is the carve-out (`View::execute()` calls `sendHeaders()` directly because Smarty streams to stdout). The cost is one extra call site to remember when adding a new cross-cutting concern.
- Apache-served static files (`favicon.ico`, anything under `htdocs/img/` etc.) do not flow through `HttpEmitter` or `View`. They remain operator-side via Apache `Header set ...` in `conf-available/`. Documented in `security-headers.md` § "Apache-served static files".
- Controller-set headers win over decorator-set headers. This is intentional (a per-endpoint override should always be possible) but means a deployment that wants the decorator's value to be authoritative must coordinate with the relevant controllers.

Neutral:

- The framework ships **no** opinionated CSP / HSTS values. The doc surfaces a starting cookbook; operators tune.
- Pre-existing reverse proxy / load balancer headers (e.g., `X-Forwarded-For`) are unaffected. The decorator only adds; it never removes.

## Addendum: Second use case landed (FT15)

Field Trial 15 (`docs/field-trials/2026-05-field-trial-15.md`) added **request-id / correlation-id** as the first non-security concern via this decorator. The change was scoped to `ResponseDecorator` internals (splitting `headers()` into a cached `staticHeaders()` plus per-call augmentation that queries `RequestId::current()`). **`HttpEmitter::emit()` and `View::execute()` did not need to change** — the boundary held under a fresh use case.

The recipe future concerns follow:

1. Add a static helper (in the FT15 case `Nene\Xion\RequestId`) that resolves the per-request value once.
2. Extend `ResponseDecorator::headers()` to fold the value in under the configured header name.
3. Optionally extend `Log::__construct()` to push a Monolog processor that injects the value into `record->extra`.
4. Add env-var rows to `compose.yaml`, `docs/development/production-deployment.md`, and the relevant `docs/development/*.md` doc.

See `docs/development/observability.md` for the full request-id walkthrough.
