# Session Storage

NeNe supports pluggable session storage backends via the `NENE_SESSION_DSN` environment variable. The default is PHP's built-in file-based session storage; Redis is available when `NENE_SESSION_DSN` is set.

## Why this matters

PHP file sessions store data in a directory on the server. With a single application instance this works perfectly. Under a load balancer with multiple app instances, requests from the same user can hit different servers — each with its own session directory — causing silent authentication loss. A shared backend such as Redis solves this.

## Default: file sessions

When `NENE_SESSION_DSN` is empty (or unset), NeNe uses PHP's default file session handler. No configuration is required for single-server deployments.

```sh
# Server install (.env or shell): leave unset or set explicitly to empty
NENE_SESSION_DSN=
```

## Redis sessions

Set `NENE_SESSION_DSN` to a Redis DSN and NeNe wires `Nene\Xion\RedisSessionHandler` before `session_start()`:

```sh
NENE_SESSION_DSN=redis://redis:6379       # default DB (0)
NENE_SESSION_DSN=redis://redis:6379/1     # DB 1
NENE_SESSION_DSN=redis://myhost:6380/2    # custom host, port, DB
```

### Docker Compose (local development)

`compose.yaml` ships a `redis:7-alpine` service and sets `NENE_SESSION_DSN=redis://redis:6379` by default. Redis starts automatically alongside MySQL; no extra steps are needed after `docker compose up -d app`.

To revert to file sessions in Docker, override in your `.env`:

```sh
NENE_SESSION_DSN=
```

### Production

Install Redis on your server (or use a managed Redis service), then set the env var in your system configuration or `.env`:

```sh
NENE_SESSION_DSN=redis://127.0.0.1:6379
# or managed Redis:
NENE_SESSION_DSN=redis://my-redis.example.com:6379/0
```

The app reads `NENE_SESSION_DSN` at startup. No code change is required.

## How it works

`Nene\Xion\SessionHandlerFactory::fromDsn(SESSION_DSN)` is called in `htdocs/index.php` between `configureSessionCookie()` and `session_start()`:

- Empty string → returns `null` → PHP file sessions (no-op).
- `redis://...` → returns `Nene\Xion\RedisSessionHandler` → registered via `session_set_save_handler($handler, true)`.

Session data is stored under the Redis key `sess_{phpSessionId}` with a TTL equal to `session.gc_maxlifetime` (PHP default 1440 seconds). Active sessions refresh their TTL on every request write. PHP's garbage collection probability (`session.gc_probability`) still fires `gc()` calls, but the handler treats them as no-ops because Redis expires keys natively.

## PHP client: predis/predis vs ext-redis

NeNe ships with `predis/predis` (pure PHP). This choice avoids a Dockerfile change and works identically in development. Drawbacks:

- Adds ~500 KB to vendor.
- ~1–2 ms per session command (acceptable for session workloads).

If you need `ext-redis` (faster, cluster-mode support), you can:

1. Add `RUN pecl install redis && docker-php-ext-enable redis` to the `Dockerfile`.
2. Implement your own handler using the `ext-redis` API and register it via `session_set_save_handler()` before `session_start()`.

The framework's `SessionHandlerFactory` dispatches on the DSN scheme; you can extend it or replace it in `htdocs/index.php` for project-specific needs.

## Adding a custom backend

`SessionHandlerFactory::fromDsn()` returns `null` for unknown schemes. To add a Memcached or DB-backed handler:

1. Create a class implementing `\SessionHandlerInterface`.
2. In `htdocs/index.php`, override `configureSessionHandler()` (or add a check before calling the factory) to handle your DSN scheme.

## ADR

Design decision: `docs/adr/0010-session-storage-backend.md` (FT18 / 2026-05-26).
