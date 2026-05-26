# ADR-0010 — Pluggable session storage backend via SessionHandlerFactory

Status: **accepted** (FT18 — 2026-05-26)

## Context

NeNe uses PHP's default file-based session storage (`session.save_handler = files`). This works correctly for single-server deployments, but breaks immediately under a load-balancer with multiple application instances because each server maintains its own session directory. The issue was listed as the third commercial-viability concern in `REPORT_commercial_feasibility.md`.

Making session storage configurable requires three things:

1. A PHP session handler (`SessionHandlerInterface`) for the target backend.
2. A factory that reads a DSN environment variable and constructs the right handler.
3. A hook in the bootstrap where the handler is registered before `session_start()`.

The FT13 Mailer pattern (one interface + one concrete implementation + one env-var + one Docker Compose service) is a close analogue for the design.

## Decision

Introduce a pluggable session storage backend controlled by the `NENE_SESSION_DSN` environment variable.

### New classes

- `Nene\Xion\RedisSessionHandler` — implements PHP's native `SessionHandlerInterface`. Stores session data as plain strings under the Redis key `sess_{id}` with a TTL equal to `session.gc_maxlifetime`. Redis handles key expiry natively; `gc()` is a deliberate no-op.
- `Nene\Xion\SessionHandlerFactory` — single entry point. Parses `SESSION_DSN` and returns a `\SessionHandlerInterface` or `null` (→ PHP default file sessions). Currently supports `redis://host:port[/db]`.

### Bootstrap hook

A new `configureSessionHandler()` function in `htdocs/index.php` is called between `configureSessionCookie()` and `session_start()`. When `SESSION_DSN` is empty, the function is a no-op — no behaviour change for existing deployments.

### DSN convention

| DSN value              | Backend                          |
|------------------------|----------------------------------|
| `` (empty)             | PHP default file sessions        |
| `redis://host:port`    | Redis via `RedisSessionHandler`  |
| `redis://host:port/db` | Redis DB N via `RedisSessionHandler` |

### PHP client

`predis/predis ^2.2` is added as a production dependency. It is pure PHP and requires no C extension in the Docker image. `ext-redis` remains an acceptable alternative for operators who can modify the Dockerfile; `SessionHandlerFactory` is intentionally decoupled from the client so the implementation can be swapped later.

### Docker Compose

A `redis:7-alpine` service is added to `compose.yaml` with a healthcheck. The `app` service gains `depends_on: redis: condition: service_healthy` so the application never starts before Redis is ready. `NENE_SESSION_DSN` defaults to `redis://redis:6379` in the compose environment, matching the Mailpit pattern (dev infrastructure available by default, replaced in production).

### Environment variable

`NENE_SESSION_DSN` is read in `ini/xSystemIni.php` and exposed as the `SESSION_DSN` constant. Empty string = file sessions (no performance cost, no connection attempt).

## Consequences

**Positive:**

- Multi-instance / load-balanced deployments now work out of the box with a single `NENE_SESSION_DSN` env change.
- Single-server deployments are unaffected — empty DSN → no behaviour change.
- The factory pattern is open to additional backends (Memcached, DB-backed) without touching the bootstrap.
- Redis TTL handles session garbage collection natively; no `gc()` logic needed.

**Trade-offs:**

- `predis/predis` is a new production dependency. It is pure PHP and well-maintained, but adds ~500 KB to vendor.
- Operators who need `ext-redis` (for performance or cluster mode) must modify the Dockerfile and write a custom handler; the factory only ships a `predis`-backed implementation.
- The compose default (`redis://redis:6379`) means every `docker compose up` now starts a Redis container even for single-server use. Operators who want file sessions in Docker must explicitly set `NENE_SESSION_DSN=` in their `.env`.

## Alternatives considered

### Use `ext-redis` only

`ext-redis` would require adding `pecl install redis && docker-php-ext-enable redis` to the Dockerfile, making the Docker image larger and the build slower. `predis/predis` eliminates this and works identically for development use cases.

### Modify `ini_set('session.save_handler', 'redis')` directly

PHP can be told to use the `redis` save handler via INI if `ext-redis` is installed. This is the fastest option in production but requires the C extension and is non-testable without a live Redis server. `SessionHandlerInterface` is testable with mocks.

### Session handler in `Initialize::init()`

Centralising the handler registration in `Initialize::init()` would reduce the surface of `htdocs/index.php`, but `Initialize::init()` currently only loads INI files and does not interact with PHP session state. Keeping session configuration in the front controller alongside `configureSessionCookie()` is the more explicit and consistent choice.

## Implementation tracking

- Trial: FT18 (`docs/field-trials/2026-05-field-trial-18.md`)
- feat PR: closes #428 (FT18 issue)
