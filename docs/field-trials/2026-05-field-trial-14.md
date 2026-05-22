# Field Trial 14 — security-headers (ResponseDecorator at the HttpEmitter boundary, ADR-0007)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #383.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT13 main (Symfony Mailer + ADR-0006 shipped, `symfony/yaml` CVE patched).
- Clone path: `/home/xi/github/NeNe-FT/ft14-security-headers/`
- Host ports: app=8094, mysql=3321, mailpit (unused here) inherited from FT13
- PHP: 8.4.21
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 63 packages |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 70 / 70 |
| `composer test:http` | 23 / 23, 1 expected skip |

## Goal

FT7 finding F-6 and FT8 finding F-4 surfaced the same structural pattern twice: **the dispatcher's early-exit paths skip `ControllerBase::run()`'s tail, so cross-cutting concerns added at the controller layer silently miss error responses**. FT8 patched the symptom (access-log entries for pre-dispatch 404/405) but the framework had no general decoration boundary.

FT14's trigger is **security headers** (CSP / HSTS / X-Frame-Options / Referrer-Policy / Permissions-Policy / X-Content-Type-Options). They are the canonical "every response should carry this" case. By implementing them, the trial designs the boundary class — `ResponseDecorator` — that any future cross-cutting concern (request IDs, Server-Timing, OTel headers) reuses without re-litigating where it hooks.

ADR-0007 records the design decision.

## Service Built

This is a framework refactor trial, not a feature trial. No new entity or controller. The "service built" is the new framework subsystem:

- `Nene\Xion\ResponseDecorator` — pure-static class, reads env once + caches, exposes `decorate(HttpResponse)` and `sendHeaders()`.
- `HttpEmitter::emit()` wraps every response through `ResponseDecorator::decorate()`.
- `View::execute()` calls `ResponseDecorator::sendHeaders()` before `Smarty::display()` (the one path that bypasses `HttpEmitter`).
- Six new env vars in `compose.yaml`: `NENE_SECURITY_CSP`, `NENE_SECURITY_FRAME_OPTIONS`, `NENE_SECURITY_REFERRER_POLICY`, `NENE_SECURITY_HSTS`, `NENE_SECURITY_PERMISSIONS_POLICY` (all empty default; opt-in by deploy). One always-on header (`X-Content-Type-Options: nosniff`) without an env var because the cost is zero.
- `tests/Unit/Xion/ResponseDecoratorTest.php` — 7 cases.
- ADR-0007 documenting the boundary choice.

## Steps Taken

### 1. Cold survey

`grep` for response decoration concerns:

- `HttpEmitter::emit()` is the chokepoint for: REST success (`JsonResponder::outputArray` throws `HttpTermination` → caught in `htdocs/index.php` → `HttpEmitter::emit()`), every `HttpTermination` (auth redirect, CSRF reject, METHOD-NOT-ALLOWED, NOT-FOUND), the `DomainException` catch, the top-level `\Throwable` catch, and `ControllerBase::sendFile()` (FT12 #368).
- `View::execute()` is the chokepoint for HTML success — `$smarty->display(...)` writes directly to stdout, bypassing `HttpEmitter`.

So **two integration points** are needed. Not one — but two is finite and well-bounded.

### 2. Design space

| Option | Where | Pros | Cons |
| --- | --- | --- | --- |
| Apache `Header set ...` in conf-available | Apache layer | universal, no PHP touched | env-driven config awkward; mixes static + dynamic; FT8 already learned the `zz-` order trap |
| `ControllerBase::run()` tail | framework layer | reuses existing hook | **the FT7 F-6 trap** — early exits skip it |
| `HttpEmitter::emit()` | framework layer | single PHP chokepoint for all responses (almost) | HTML success still bypasses it (F-1) |
| Middleware framework | framework layer | maximally flexible | NeNe philosophy = small framework |

Chosen: **`HttpEmitter::emit()` + a second hook at `View::execute()`**. Two locations, both small, fully covering all PHP-generated responses. Static files served by Apache are still operator-side (out of scope, F-4).

ADR-0007 records this choice and why the alternatives lose.

### 3. Implementation

`ResponseDecorator` (~100 lines):

```php
public static function headers(): array {
    if (self::$cached === null) {
        $headers = ['X-Content-Type-Options' => 'nosniff'];
        foreach (self::ENV_MAP as $env => $header) {
            $value = (string)(getenv($env) ?: '');
            if ($value !== '') {
                $headers[$header] = $value;
            }
        }
        self::$cached = $headers;
    }
    return self::$cached;
}

public static function decorate(HttpResponse $response): HttpResponse {
    // existing controller headers win; case-insensitive match
}

public static function sendHeaders(): void {
    // for View::execute() — calls PHP header() before Smarty echoes
}
```

`HttpEmitter::emit()` change: one line, wraps the response through `decorate()`.

`View::execute()` change: one line, calls `sendHeaders()` before `display()`.

`compose.yaml`: five env passthroughs.

### 4. Live verification

```
$ curl -sS -i http://127.0.0.1:8094/health | grep X-Content
X-Content-Type-Options: nosniff

$ curl -sS -i http://127.0.0.1:8094/no-such | grep X-Content        # HTML 404 (404.html)
X-Content-Type-Options: nosniff

$ curl -sS -i -H 'Accept: application/json' http://127.0.0.1:8094/no-such | grep X-Content
X-Content-Type-Options: nosniff                                       # REST 404 (NOT-FOUND envelope)

$ curl -sS -i http://127.0.0.1:8094/ | grep X-Content                 # HTML success (Smarty)
X-Content-Type-Options: nosniff

$ curl -sS -i http://127.0.0.1:8094/session/login | grep -E "X-Content|Allow"  # 405
Allow: POST
X-Content-Type-Options: nosniff
```

Five response paths, all carrying the header. The FT7 F-6 + FT8 F-4 trap is closed.

With env overrides (CSP + X-Frame-Options + HSTS set), all three additional headers appear on every path including HTML.

### 5. Unit tests

`tests/Unit/Xion/ResponseDecoratorTest.php` pins:

- always-on `X-Content-Type-Options: nosniff` default
- env-driven CSP / HSTS opt-in
- controller-set header beats decorator (case-insensitive match)
- `reset()` semantics (cache invalidation between assertions)
- status code + body untouched

7 cases, all green. Total: 70 → 77 (FT13 left us at 70, +7 from FT14).

### 6. Friction inventory

Implementation landed clean. Findings are mostly informational + one docs-gap.

- **F-1** (informational): `View::execute()` bypasses `HttpEmitter` — handled by the second integration point. Documented in ADR-0007.
- **F-2** (informational): HTTP header names are case-insensitive; decorator merge uses `array_change_key_case` to respect controller overrides written in any case. Unit-tested.
- **F-3** (medium docs-gap): No recommended CSP / HSTS *values* in framework docs — operators get the var list without guidance. The new `security-headers.md` doc fills this gap with a starting-cookbook section.
- **F-4** (low informational): Apache-served static files (e.g. `favicon.ico`) do not flow through PHP and are not covered. Documented as an operator-side concern (Apache `Header set ...` in conf-available, the same path FT8 #330 used for `ServerTokens`).
- **F-5**: FT7 F-6 + FT8 F-4 are resolved by this trial. The reference notes in `docs/development/error-codes.md` need a cross-link to ADR-0007.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| REST success carries `X-Content-Type-Options: nosniff` | yes | yes | Pass |
| REST 404 carries the header | yes | yes (closes FT7 F-6) | Pass |
| HTML success carries the header (Smarty path) | yes | yes (via `View::execute` hook) | Pass |
| HTML 404 (`404.html`) carries the header | yes | yes | Pass |
| 405 method-not-allowed carries the header | yes | yes (existing `Allow:` preserved) | Pass |
| `NENE_SECURITY_CSP="..."` propagates to all paths | yes | yes | Pass |
| Controller-set header (`X-Frame-Options: SAMEORIGIN` on one endpoint) wins over decorator | yes | yes (unit test) | Pass |
| Apache-served static files carry the header | no (out of scope, F-4) | no | Partial (documented) |
| Static analysis baseline does not regress | yes | yes (composer test 77/77) | Pass |
| ADR-0007 drafted | yes | yes (Accepted) | Pass |

## Friction Summary

| ID  | Location                                       | Severity | Kind            | Decision        |
| --- | ---------------------------------------------- | -------- | --------------- | --------------- |
| F-1 | `class/xion/View.php::execute`                 | low      | informational   | document        |
| F-2 | `class/xion/ResponseDecorator.php`             | low      | informational   | document        |
| F-3 | `docs/development/security-headers.md` (new)   | medium   | docs-gap        | document        |
| F-4 | (Apache layer)                                 | low      | informational   | document        |
| F-5 | (FT7 F-6 + FT8 F-4 predictions)                | medium   | resolved        | cross-link      |

## Recommendations

### Immediate (feat PR)

1. **ADR-0007 + ResponseDecorator + `HttpEmitter` hook + `View::execute` hook + `compose.yaml` env + unit tests.** One PR. The ADR documents the boundary choice; the helpers ship the implementation; the env vars wire production; the tests pin behavior.

### Immediate (docs PR)

1. **`docs/development/security-headers.md` (new).** Six headers documented (one always-on, five opt-in), recommended starting values for CSP / HSTS / Referrer-Policy / Permissions-Policy, the Apache-layer note for static files (F-4), the controller-wins precedence (F-2), the two-integration-point shape (F-1).
2. **`docs/development/production-deployment.md`** env matrix gets five new rows.
3. **`docs/development/error-codes.md`** "decoration trap" section cross-links to ADR-0007 with "resolved by FT14 / ADR-0007 — see `security-headers.md` for the boundary class" (F-5).
4. **`docs/review/security-headers.md` (new, optional)** — short checklist for reviewers (controller did not override Critical header unintentionally; env was set in `compose.prod.yaml`; CSP value tested with Swagger UI / Smarty page).
5. **AGENTS.md** Read-First link.

### Trade-offs

The two-integration-point shape (`HttpEmitter` + `View::execute`) is not as clean as a single chokepoint, but routing Smarty output through `HttpEmitter` would require buffering the entire rendered template into a string (doubling memory for large pages). The trial accepts the two-point shape; ADR-0007 records the trade-off.

## Aftermath

- ADR-0007 (`docs/adr/0007-response-decoration-boundary.md`) Accepted.
- One feat PR + one docs PR. F-3 / F-4 absorbed into the docs PR. F-5 closed via the cross-link in the docs PR.
- `docs/field-trials/follow-ups.md` does not need an FT14-derived entry (no deferred friction).
- FT15 can pick whatever is next.
