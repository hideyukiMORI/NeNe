# Observability

How NeNe tags every request with a stable identifier, propagates it into logs and response headers, selects a log output format, and how future observability concerns (server-timing, OpenTelemetry, audit fingerprints) plug into the same boundary.

Audience: anyone debugging across multiple log lines that should belong to the same request, anyone configuring NeNe behind a reverse proxy / load balancer, anyone adding a new cross-cutting concern. Trial sources: FT15 (request-id), FT19 (structured-logs), FT20 (server-timing). Boundary: ADR-0007.

## Request-id (correlation id)

Every PHP-generated response carries an `X-Request-ID` header. Every log record carries a matching `request_id` in its `extra` block. Operators grep one id and see exactly the lines for one request.

```
$ curl -i http://localhost:8080/health | grep X-Request-ID
X-Request-ID: 82ad54e03eabb286aad4e020d5bae21e

$ grep 82ad54e03eabb286aad4e020d5bae21e log/access-*.log log/error-*.log
log/access-2026-05-22.log:[...] Nene.INFO: ACCESS : health::index [...] {"request_id":"82ad54e03eabb286aad4e020d5bae21e"}
```

### Resolution order

`Nene\Xion\RequestId::current()` resolves the id once per request, in this order:

1. If `NENE_REQUEST_ID_TRUST_INBOUND` is truthy (default `1`), read the header named by `NENE_REQUEST_ID_HEADER` (default `X-Request-ID`). If the value passes `isValid()`, honor it.
2. Otherwise generate a fresh value via `bin2hex(random_bytes(16))` (32 hex chars).

The result is cached for the duration of the request. Subsequent `current()` calls — from the response header decorator and from every log processor — see the same value.

### Validation

A request-id that survives validation is safe to embed in log files and response headers without further escaping.

| Rule | Value |
| --- | --- |
| Allowed charset | `[A-Za-z0-9_\-\.:]+` (alphanumeric plus `_`, `-`, `.`, `:`) |
| Max length | 128 characters |
| Empty | rejected (treated as missing) |

Anything that fails validation is silently replaced by a generated id. This blocks:

- Log poisoning (no newlines / control chars survive).
- Unbounded length (no log-line bloat).
- Header injection (no CR / LF / colon shenanigans).

The strict policy is intentional. If your upstream proxy uses a different shape, override `NENE_REQUEST_ID_HEADER` (e.g. set it to your proxy's existing name) and ensure the proxy emits a valid value.

### Env vars

| Variable | Default | Production-safe value | What it controls |
| --- | --- | --- | --- |
| `NENE_REQUEST_ID_HEADER` | `X-Request-ID` | `X-Request-ID` (or your proxy's name) | Header name read on inbound and emitted on outbound. Empty string disables outbound emission (logs still tag). |
| `NENE_REQUEST_ID_TRUST_INBOUND` | `1` | `1` behind a trusted proxy; `0` when the public boundary is the app itself | Whether to honor an inbound header value. Generates fresh when `0`. |

## Log format (structured / JSON logging)

**FT19.** `NENE_LOG_FORMAT` selects the Monolog formatter for all three log channels (access / information / error):

| Value | Formatter | Output |
| --- | --- | --- |
| `text` (default) | `Monolog\Formatter\LineFormatter` | Human-readable; good for `tail -f` |
| `json` | `Monolog\Formatter\JsonFormatter` | One JSON object per line; ingest-ready for Datadog / Loki / Elasticsearch |

```sh
# Production: JSON for log aggregator
NENE_LOG_FORMAT=json

# Local dev: text (default, can omit)
NENE_LOG_FORMAT=text
```

Sample JSON line:

```json
{
  "message": "ACCESS : health::index",
  "level_name": "INFO",
  "channel": "Nene",
  "datetime": "2026-05-27T00:28:05.512350+09:00",
  "context": ["curl/7.81.0", ""],
  "extra": { "request_id": "acdc435d00a2d19dd437212b92f785f7" }
}
```

The `extra.request_id` added by the FT15 processor is present in both formats. A log aggregator field-extraction rule for `extra.request_id` works identically in JSON mode.

**Implementation**: `Nene\Xion\LogFormatterFactory::create(string $format)` returns the appropriate `FormatterInterface`. `Log::__construct()` calls `LogFormatterFactory::fromConstant()` which reads the `LOG_FORMAT` constant (from `NENE_LOG_FORMAT`). Unknown values fall back to `text` silently.

## Log integration (request_id processor)

`Nene\Xion\Log::__construct()` pushes a Monolog processor onto every Logger (access / information / error) that adds `request_id` to each record's `extra` array:

```php
$requestIdProcessor = static function (\Monolog\LogRecord $record): \Monolog\LogRecord {
    return $record->with(extra: array_merge($record->extra, ['request_id' => RequestId::current()]));
};
```

`extra` (not `context`) is the right slot for processor-appended fields:

- `context` is reserved for caller-supplied data passed to `$log->info('msg', $context)`.
- `extra` is for cross-cutting metadata added by processors.

Monolog's default formatter renders `extra` at the end of each line as a JSON object, which keeps `request_id` consistent across access / error / info logs and friendly to `grep` / `jq`.

## ResponseDecorator integration

`Nene\Xion\ResponseDecorator::headers()` returns the env-driven `NENE_SECURITY_*` headers plus the per-request request-id, in this shape:

```php
public static function headers(): array {
    $headers = self::staticHeaders();  // cached process-wide
    if (RequestId::headerName() !== '') {
        $headers[RequestId::headerName()] = RequestId::current();
    }
    return $headers;
}
```

The split between cached (`staticHeaders()`) and per-call (`headers()`) is intentional: env-driven security headers do not change within a process, but the request-id does change per request. Splitting keeps both costs at the right level.

## Server-Timing header

**FT20.** `NENE_SERVER_TIMING_ENABLED` opts the application into emitting a `Server-Timing` response header (default off):

```sh
# Enable (e.g. for staging / production behind trusted proxy)
NENE_SERVER_TIMING_ENABLED=1

# Default: off
NENE_SERVER_TIMING_ENABLED=0
```

Sample header:

```
Server-Timing: app;dur=26.9
```

`app` is the PHP application layer metric. `dur` is elapsed milliseconds (one decimal place) from bootstrap to header serialisation.

**Security note**: The header exposes internal latency. Enable only behind a trusted reverse proxy, not directly at the public edge — timing data can assist side-channel analysis on auth or rate-limited paths.

**Implementation**: `Nene\Xion\ServerTiming::start()` is called immediately after `require_once 'vendor/autoload.php'` in `htdocs/index.php`. `ResponseDecorator::headers()` adds the header when `ServerTiming::isEnabled()`. The controller-wins precedence from ADR-0007 is preserved — a controller that sets `Server-Timing` itself takes priority.

## Future cross-cutting concerns

ADR-0007 introduced the decoration boundary; FT15–FT20 validated it with four use cases. Future concerns follow the same recipe:

| Concern | Likely env var(s) | Likely shape |
| --- | --- | --- |
| OpenTelemetry `traceparent` / `tracestate` | `NENE_OTEL_*` | Parse inbound `traceparent`, generate fresh when absent (same resolution shape as request-id). Plug into `headers()` and Monolog. |
| Audit fingerprint / actor hash | `NENE_AUDIT_*` | Per-request hash of `(user_id, ip, user-agent)`. Plug into Monolog processor for tamper-evident audit logs. |
| Multi-metric Server-Timing (`db;dur=X`) | extend `ServerTiming` | Add `ServerTiming::addMetric(string $name, float $dur)` once a DB-layer hook surfaces the need. |

Each follows the FT15 recipe: a static helper resolves the per-request value, `ResponseDecorator::headers()` appends it, `Log` processor injects it into `extra`. No changes to `HttpEmitter::emit()` or `View::execute()` should be needed.

## Behind a reverse proxy

When NeNe runs behind nginx / HAProxy / a CDN, the proxy is typically the one generating `X-Request-ID`. Keep `NENE_REQUEST_ID_TRUST_INBOUND=1` (default) so NeNe honors the proxy's value, and the same id propagates to NeNe's logs.

Configure the proxy to set the header on every inbound request:

```nginx
# nginx
proxy_set_header X-Request-ID $request_id;
```

```
# HAProxy
http-request set-header X-Request-ID %[uuid()]
```

Verify with `curl -i` against the public URL — the response should echo a stable id per request.

## Standalone (no proxy)

When the app is the boundary, the inbound header is untrusted. Either:

- Set `NENE_REQUEST_ID_TRUST_INBOUND=0` to always generate (recommended unless you actively want clients to set their own id).
- Keep the default `1` but rely on `isValid()` to reject anything malformed (the typical case — clients rarely send the header at all).

## Related

- `docs/adr/0007-response-decoration-boundary.md` — the decoration boundary.
- `docs/development/security-headers.md` — first use case (security headers).
- `docs/development/production-deployment.md` — full env-var matrix, including the `NENE_REQUEST_ID_*` and `NENE_LOG_FORMAT` rows.
- `docs/development/coding-standards.md` § "Environment-variable defaults" — the `getenv() ?:` falsy-trap note (FT15 F-1).
- `docs/field-trials/2026-05-field-trial-15.md` — FT15 (request-id).
- `docs/field-trials/2026-05-field-trial-19.md` — FT19 (structured-logs).
- `docs/field-trials/2026-05-field-trial-20.md` — FT20 (server-timing).
- `class/xion/RequestId.php` — request-id implementation.
- `class/xion/LogFormatterFactory.php` — formatter selection implementation.
- `class/xion/ServerTiming.php` — Server-Timing implementation.
