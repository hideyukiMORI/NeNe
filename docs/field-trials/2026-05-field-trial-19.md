# Field Trial 19 — structured-logs

**Date**: 2026-05-27
**Branch**: `feat/431-structured-logs`
**Issue**: #431
**PRs**: #432 (feat), #433 (docs)
**Size**: small (~half session)

## Baseline

- NeNe ref: `82a68dd` (post-FT18)
- Clone: `../NeNe-FT/ft19-structured-logs/`
- PHP 8.4.21, Monolog 3.x (already a project dependency)
- 154 unit tests, all green before changes
- Log format: Monolog default `LineFormatter` (text)

## Goal

Validate the candidate description from `docs/field-trials/candidates.md`:

> Monolog supports JSON formatter natively. Production log aggregators (Datadog / Loki / Elasticsearch) want JSON. Currently text-only. Likely shape: `NENE_LOG_FORMAT=json` env swap, default text. No ADR needed — pure formatter selection.

## Findings

### F-1 — `JsonFormatter` already ships with Monolog (no new dependency)

`JsonFormatter` is in `monolog/monolog` which NeNe already requires. Zero new `composer.json` entries needed. The candidate's scope estimate of "pure formatter selection" was accurate.

### F-2 — `Log` singleton is hard to unit-test directly (path-constant coupling)

`Log::__construct()` references `ACCESS_LOG_PATH`, `APP_LOG_PATH`, `ERROR_LOG_PATH` (defined in `ini/xSystemIni.php`). The PHPUnit bootstrap loads only `vendor/autoload.php`, so those constants are undefined in tests. Instantiating `Log` in a unit test fails.

**Resolution**: extract formatter selection into a standalone `LogFormatterFactory` class that has no dependency on path constants. `Log` delegates to `LogFormatterFactory::fromConstant()`. This gives a clean, testable seam.

### F-3 — Formatter applied consistently to all three channels

Access, information, and error channels all share one formatter instance built at construction time. Operators get uniform JSON (or text) across all three log files — no partial JSON format mismatch.

### F-4 — `extra.request_id` survives JSON mode (FT15 processor preserved)

The `RequestId` Monolog processor added by FT15 writes into `extra.request_id`. JSON output includes the full `extra` object:

```json
{
  "message": "ACCESS : health::index",
  "level_name": "INFO",
  "channel": "Nene",
  "datetime": "2026-05-27T00:28:05.512350+09:00",
  "extra": { "request_id": "acdc435d00a2d19dd437212b92f785f7" }
}
```

Log aggregator queries by `extra.request_id` work without any additional processor changes.

### F-5 — Unknown `NENE_LOG_FORMAT` values fall back to text (fail-safe)

`LogFormatterFactory::create()` treats any unrecognised string as `text`. A typo like `NENE_LOG_FORMAT=jsno` silently degrades to text rather than throwing. Logged to error log via `error_log()` is considered unnecessary overhead for a formatter selection — the env var name is visible in the deploy config.

## Implementation

```
class/xion/LogFormatterFactory.php  (new)
class/xion/Log.php                  (modified: delegate formatter selection)
ini/xSystemIni.php                  (modified: LOG_FORMAT constant)
compose.yaml                        (modified: NENE_LOG_FORMAT env entry)
tests/Unit/Xion/LogFormatterTest.php (new, 7 cases)
```

No ADR filed — formatter selection is not an architectural decision; it is a deployment configuration knob.

## Results

| Check | Result |
| --- | --- |
| `composer test` | 161 tests, 334 assertions, ✅ |
| `composer analyze` | Phan 0 new issues ✅ |
| `composer format:check` | CS Fixer clean ✅ |
| Live text mode | `[2026-05-27…] Nene.INFO: ACCESS : health::index …` ✅ |
| Live JSON mode | `{"message":"ACCESS : health::index","level_name":"INFO","extra":{"request_id":"…"}}` ✅ |
| `jq` parse | Clean, all expected fields present ✅ |

## Recommendations

1. **Document `NENE_LOG_FORMAT`** in `docs/development/observability.md` alongside `NENE_LOG_PATH` and `NENE_REQUEST_ID_HEADER` — all three are observability env vars.
2. **Production deployment checklist**: add `NENE_LOG_FORMAT=json` to the recommended production env vars in `docs/development/production-deployment.md`.
3. **`NENE_LOG_FORMAT` does not add a new candidate** to `docs/field-trials/candidates.md` — the structured-logs candidate is fully resolved.
4. **Future**: if a third format (e.g. logstash-json) is needed, extend `LogFormatterFactory::create()` with a new `case`; `Log.php` and `ini/xSystemIni.php` need no changes.

## Candidate status

`structured-logs (JSON formatter swap)` → **complete**. Remove from active candidates list.
