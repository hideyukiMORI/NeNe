# Field Trial 15 — request-id (cross-cutting decoration's second use case, validates ADR-0007 generality)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #389.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT14 main (ResponseDecorator + ADR-0007 shipped).
- Clone path: `/home/xi/github/NeNe-FT/ft15-request-id/`
- Host ports: app=8095, mysql=3322
- PHP: 8.4.21
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `composer install` | 63 packages |
| `/health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 77 / 77 |
| `composer test:http` | 23 / 23, 1 expected skip |

## Goal

ADR-0007 (FT14) introduced `ResponseDecorator` as the cross-cutting concern boundary. The first use case was security headers, but the ADR's claim is **general**: any future cross-cutting concern plugs in the same way.

FT15 validates that claim by wiring **request-id / correlation-id** — an observability concern, not a security one. The trial succeeds if:

1. The new helper lands without touching the `HttpEmitter` / `View::execute` hooks.
2. The pattern matches FT14's helper shape closely enough to feel like the same framework.
3. The new helper is useful in isolation (response header + log correlation).

## Service Built

This is a refactor/feature trial in the same shape as FT14, not a real-app entity trial.

- `Nene\Xion\RequestId` — pure-static helper, resolves once per request (inbound `X-Request-ID` if valid, else generates 32-char hex via `random_bytes(16)`).
- `ResponseDecorator::headers()` now folds in `RequestId::current()` under the configured header name.
- `Log::__construct()` pushes a Monolog processor on every logger that adds `request_id` to each record's `extra` context.
- Two env vars: `NENE_REQUEST_ID_HEADER` (default `X-Request-ID`, empty = no emission) and `NENE_REQUEST_ID_TRUST_INBOUND` (default `1`).
- 12 unit cases in `tests/Unit/Xion/RequestIdTest.php`.

## Steps Taken

### 1. Cold survey

`grep -rnE 'X-Request-Id|REQUEST_ID|request_id|correlation' --include='*.php'` returned zero matches. Empty surface, expected.

### 2. Design

Resolution order:

1. If `NENE_REQUEST_ID_TRUST_INBOUND` is truthy (default), read the header named by `NENE_REQUEST_ID_HEADER`. If it passes `isValid()` (`[A-Za-z0-9_\-\.:]+`, max 128 chars), honor it.
2. Otherwise generate `bin2hex(random_bytes(16))`.

Validation is strict on purpose: the value lands in log files and response headers, so anything that could line-break or balloon length is rejected and replaced silently.

### 3. ResponseDecorator extension

The existing `headers()` cached the env-driven static set in a private array. Request-id has to be queried fresh per request. Split `headers()` into:

```php
public static function headers(): array {
    $headers = self::staticHeaders();  // cached env-driven
    if (RequestId::headerName() !== '') {
        $headers[RequestId::headerName()] = RequestId::current();
    }
    return $headers;
}
```

`HttpEmitter::emit()` and `View::execute()` did not need to change. The FT14 boundary held.

### 4. Log processor

Each Logger constructed by `Log` gets the same processor pushed:

```php
$requestIdProcessor = static function (\Monolog\LogRecord $record): \Monolog\LogRecord {
    return $record->with(extra: array_merge($record->extra, ['request_id' => RequestId::current()]));
};
```

The result lands in the rotating-file formatter at the end of each line as `{"request_id":"..."}`.

### 5. Live verification

```
$ curl -i http://127.0.0.1:8095/health | grep X-Request
X-Request-ID: 82ad54e03eabb286aad4e020d5bae21e          # generated

$ curl -i -H 'X-Request-ID: my-trace-abc-123' http://127.0.0.1:8095/health | grep X-Request
X-Request-ID: my-trace-abc-123                          # honored

$ curl -i -H "X-Request-ID: bad value with spaces" http://127.0.0.1:8095/health | grep X-Request
X-Request-ID: 156bfffe506ee608c245c9be9ed3ce9d          # rejected → generated

$ tail log/access-*.log
[...] ACCESS : health::index [...] {"request_id":"82ad54e03eabb286aad4e020d5bae21e"}
[...] ACCESS : health::index [...] {"request_id":"my-trace-abc-123"}
[...] ACCESS : health::index [...] {"request_id":"156bfffe506ee608c245c9be9ed3ce9d"}
```

Response header and log record carry the **same** id per request. Operators grep one id and see everything for that request.

### 6. Unit tests

12 cases pin: generated default, inbound honored, invalid inbound rejected (whitespace, control chars, over-length), trust-disabled path, header-name override, cache + reset semantics, header name default.

### 7. Friction inventory

One real bug surfaced; rest are informational.

- **F-1 — `getenv() ?: 'default'` falsy trap.** The initial `trustsInbound()` returned true even when `NENE_REQUEST_ID_TRUST_INBOUND=0`, because `'0' ?: '1'` is `'1'` in PHP. Caught by the unit test (`testGeneratesWhenTrustDisabled` failed first run). Fixed by reading `getenv()` directly and checking `=== false`. **The idiom is widespread in NeNe's other helpers** (Mailer, Log, etc.) — but for those vars `'0'` is not a meaningful value, so the bug is dormant. A doc note is enough.
- **F-2 — Monolog `extra` vs `context` placement.** Putting `request_id` in `extra` keeps it out of caller-supplied `context` keys and matches Monolog convention. Documented as the recipe for future processors.
- **F-3 — `View::execute()` header ordering.** `ResponseDecorator::sendHeaders()` runs **before** Smarty's `display()` so Smarty's later `header()` calls do not clobber decorator headers. The existing `headers_sent()` guard in `sendHeaders()` saved the day; no fix needed.
- **F-4 — Lazy vs eager resolution.** Considered resolving the id eagerly in `Initialize::init()`. Decided no — lazy `current()` is enough, avoids ordering questions with `EnvLoader`.
- **F-5 — ADR-0007 generality validated.** The decorator boundary hosted the second use case (observability) without architectural changes. Splitting `headers()` into `staticHeaders()` + per-request augmentation was an internal refactor inside `ResponseDecorator`, not a change to its public contract.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Generated request-id appears on every response | yes | yes (FT14 boundary inherited) | Pass |
| Inbound `X-Request-ID` is honored when valid | yes | yes | Pass |
| Inbound that contains spaces / newlines / 129+ chars is rejected | yes | yes | Pass |
| Trust can be disabled via env | yes | yes (after F-1 fix) | Pass |
| Header name is env-overridable | yes | yes | Pass |
| Empty header name disables response emission | yes | yes (log still tags) | Pass |
| Log records carry `request_id` in `extra` | yes | yes | Pass |
| Two requests get different generated ids | yes | yes | Pass |
| `HttpEmitter` / `View::execute` hooks unchanged | yes | yes (validates ADR-0007 generality) | Pass |
| `composer test` passes | yes | 89 / 89 (77 + 12 new) | Pass |

## Friction Summary

| ID  | Location                                       | Severity | Kind            | Decision        |
| --- | ---------------------------------------------- | -------- | --------------- | --------------- |
| F-1 | `getenv() ?:` idiom (audit other helpers)      | low      | informational   | document        |
| F-2 | Monolog processor convention                   | low      | informational   | document        |
| F-3 | `View::execute` header ordering                | low      | informational   | (no change)     |
| F-4 | Resolution timing (lazy vs eager)              | low      | informational   | (no change)     |
| F-5 | ADR-0007 generality                            | medium   | validation      | confirm in doc  |

## Recommendations

### Immediate (feat PR)

1. **`RequestId` + ResponseDecorator extension + Log processor + compose env + tests.** One PR — landed together because they validate as a single end-to-end check.

### Immediate (docs PR)

1. **New `docs/development/observability.md`**: request-id behavior (inbound honoring, generation, validation rules, log correlation, future Server-Timing / OTel slot).
2. **`docs/development/security-headers.md`**: small forward-link to the new observability doc noting "the same decorator hosts observability concerns".
3. **`docs/adr/0007-response-decoration-boundary.md`**: add a "Second use case (FT15)" line in Consequences to confirm generality.
4. **`docs/development/production-deployment.md`** env matrix: two new rows (`NENE_REQUEST_ID_HEADER`, `NENE_REQUEST_ID_TRUST_INBOUND`).
5. **`AGENTS.md`** Read-First link.
6. **F-1 audit / doc note**: short paragraph in coding-standards explaining the `getenv() ?:` falsy trap so future helpers avoid it.

### Trade-offs

None blocking. The trial confirmed ADR-0007's bet — a fresh cross-cutting concern landed without touching the boundary code, only extending the decorator's headers map. Future concerns (Server-Timing, OTel `traceparent`, audit fingerprints) follow the same pattern.

## Aftermath

- Probe is not needed (refactor trial, not entity trial).
- Two PRs: feat (helper + extension + tests) + docs (`observability.md` + matrix + ADR addendum).
- FT16 can pick whatever is next. The remaining priorities from the FT13 candidate list are session-storage-backend (Redis), background-jobs, structured logs, or a meta trial (ai-agent-journey).
