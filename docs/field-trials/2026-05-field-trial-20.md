# Field Trial 20 — server-timing

**Date**: 2026-05-27
**Branch**: `feat/434-server-timing`
**Issue**: #434
**PRs**: #435 (feat), #436 (docs)
**Size**: small (~half session)

## Baseline

- NeNe ref: `df43735` (post-FT19)
- Clone: `../NeNe-FT/ft20-server-timing/`
- PHP 8.4.21, Monolog 3.x
- 161 unit tests, all green before changes

## Goal

Validate the ADR-0007 "future cross-cutting concern" described in `docs/development/observability.md`:

> `Server-Timing` header: per-request timing data for browser devtools / aggregators. Add to `headers()` via a `ServerTiming::current()` analogue.

## Findings

### F-1 — `hrtime(true)` is the right clock (monotonic, nanosecond resolution)

`microtime()` is sufficient for millisecond timing but is wall-clock (can drift backward with NTP). `hrtime(true)` is monotonic and returns nanoseconds as int — ideal for sub-millisecond Server-Timing values. Division by `1_000_000` converts to milliseconds.

### F-2 — Start point matters: call `start()` before `EnvLoader::loadIfExists()`

The `index.php` bootstrap order: autoload → `ServerTiming::start()` → `EnvLoader` → `Initialize` → session → dispatch. Placing `start()` immediately after `require_once 'vendor/autoload.php'` maximises the measured window. Moving it after `Initialize::init()` would miss the ~5ms it takes to parse `xSystemIni.php` and load Smarty internals — a non-trivial fraction of a 25ms request.

### F-3 — Per-request, not cached — follows `RequestId` shape exactly

`elapsed()` re-reads `hrtime(true)` on every call, so `ResponseDecorator::headers()` gets the duration up to the moment the header set is assembled (not a stale snapshot from request start). The ADR-0007 `staticHeaders()` / `headers()` split holds: `Server-Timing` belongs in `headers()` alongside `X-Request-ID`.

### F-4 — Off by default is the correct security posture

`Server-Timing` exposes internal latency. On auth paths or rate-limited endpoints, a precise millisecond reading can assist timing-based attacks (e.g., distinguishing "user not found" from "wrong password" via response time). Opt-in (`NENE_SERVER_TIMING_ENABLED=1`) forces operators to make an explicit decision. This matches the `NENE_SECURITY_*` philosophy.

### F-5 — Controller-wins precedence preserved

`ResponseDecorator::decorate()` already has case-insensitive "existing header wins" logic. A controller that sets its own `Server-Timing` (e.g. for custom metric breakdown) takes priority — no special handling needed.

## Implementation

```
class/xion/ServerTiming.php              (new)
class/xion/ResponseDecorator.php         (modified: headers() wiring)
htdocs/index.php                         (modified: start() call)
compose.yaml                             (modified: env entry)
tests/Unit/Xion/ServerTimingTest.php     (new, 14 cases)
tests/Unit/Xion/ResponseDecoratorTest.php (modified: 3 new cases)
```

No ADR filed — ADR-0007 already documents the decoration boundary and explicitly lists `Server-Timing` as a future use case. This trial is that use case.

## Results

| Check | Result |
| --- | --- |
| `composer test` | 178 tests, 352 assertions ✅ |
| `composer analyze` | Phan 0 new issues ✅ |
| `composer format:check` | CS Fixer clean ✅ |
| `NENE_SERVER_TIMING_ENABLED=1` | `Server-Timing: app;dur=26.9` ✅ |
| Default (off) | Header absent ✅ |
| Controller override | Controller value wins ✅ |

## Recommendations

1. **Document in `observability.md`**: add a Server-Timing section alongside the existing request-id and log-format sections.
2. **Production deployment**: add `NENE_SERVER_TIMING_ENABLED` to the env-var matrix, recommend enabling behind a trusted reverse proxy only (not directly at the public edge).
3. **Next step** for Server-Timing: if multiple timing checkpoints are needed (e.g. `db;dur=X` separate from `app;dur=X`), `ServerTiming::addMetric(string $name, float $dur)` would be a natural extension — but that requires a DB-layer hook. Deferred until a real deploy surfaces the need.

## Candidate status

`Server-Timing header` → **complete**. Remove from active candidates list.
