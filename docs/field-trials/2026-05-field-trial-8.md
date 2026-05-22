# Field Trial 8 — production-mode (APP_ENV=production / APP_DEBUG=0 / SESSION_SECURE=1 + log + image audit)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #326.

## Date

2026-05-22

## Baseline

- NeNe ref: `06b3dbc` (post-FT7 main with all #312–#318 follow-up PRs merged)
- Clone path: `/home/xi/github/NeNe-FT/ft8-production-mode/` (created via `tools/nene-ft-new.sh production-mode`)
- Host ports: app=8088, mysql=3315 (auto-offset N=8)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default) and SQLite (parity)

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` (default env) | HTTP 200, `Set-Cookie` *without* `Secure`, body reports `environment=development` |
| `composer test` | 50 / 50, 134 assertions |
| `composer test:http` (in-container `NENE_HTTP_BASE_URL=http://localhost`) | 23 / 23, 219 assertions, 1 expected skip |
| `docker compose down && up -d app` with `.env` augmented `NENE_APP_ENV=production` + `NENE_APP_DEBUG=0` + `NENE_SESSION_SECURE=1` | HTTP 200, `Set-Cookie: ...; **secure**; HttpOnly; SameSite=Lax`, body reports `environment=production` |

## Goal

Exercise NeNe's **production deployment surface** end-to-end. FT1–FT7 all ran with development defaults. This trial flips `APP_ENV` / `APP_DEBUG` / `SESSION_SECURE` and observes (a) whether the FT7 envelope/template work stays clean with `APP_DEBUG=0`, (b) whether the framework's server-banner / image / log surface is shippable as-is.

The trial is deliberately end-to-end-observational. The aim is to surface "what would surprise a maintainer the first time they deploy this for real," not to ship production hardening in the trial itself.

## Service Built

- Name: `ft8probe` — one **non-committed** probe controller throwing `\RuntimeException` and `Xion\DomainException` from REST and HTML methods, with a deliberately distinctive exception message (`"FT8 probe: secret detail abc123"`) so any leak into the response body would be obvious.
- Domains: none.
- Surface: ~6 ad-hoc URLs hit with `curl`, plus log file inspection.
- DB tables: none added.

The probe lives only in the clone. It is not part of the framework deliverable.

## Steps Taken

### 1. Baseline + production env flip

Verified baseline tests + default `/health` in the FT8 clone, then augmented `.env` with the three production env vars and `docker compose down && up`. The Set-Cookie attribute changed in lockstep (`secure` appeared) and `/health` reported `environment=production`. No other action was needed to "enter" production — `APP_ENV=production` flips the safe defaults of `APP_DEBUG` and `SESSION_COOKIE_SECURE` via `ini/xSystemIni.php` (lines 82–87, 100–105).

### 2. Cold env survey

Mapped the env vars that flip prod behavior:

- `NENE_APP_ENV` → `APP_ENV` (`development` default).
- `NENE_APP_DEBUG` → `APP_DEBUG` (defaults to `0` when `APP_ENV=production`, else `1`).
- `NENE_SESSION_SECURE` → `SESSION_COOKIE_SECURE` (same default rule).
- `NENE_SESSION_HTTPONLY`, `NENE_SESSION_SAMESITE`, `NENE_SESSION_LIFETIME` — independent of env.
- `NENE_LOGOUT_URI` → `LOGOUT_URI` (covered by FT5 #284).

Found no `NENE_LOG_PATH` analogue. The log directory is hard-coded to `DIR_ROOT . 'log/'`.

**Finding (F-7)**: Setting `APP_ENV=production` *alone* flips `APP_DEBUG=0` and `SESSION_COOKIE_SECURE=1` via the conditional defaults. Forgetting to set `APP_ENV` leaves Secure cookies *off* even if every other env var is explicit. Worth highlighting in the production-deployment doc.

### 3. Production error rendering tour (re-validate FT7 fixes)

Hit each FT7 fix under `APP_DEBUG=0`:

- 404 default Accept → HTML `404.html` (unchanged) ✓
- 404 `Accept: application/json` → JSON `NOT-FOUND` envelope (#312 holds in prod) ✓
- REST `\Throwable` → JSON `INTERNAL-ERROR` envelope. **"FT8 probe: secret detail abc123" did NOT appear in the response body.** (#313 holds in prod) ✓
- HTML `\Throwable` → `500.html` (unchanged) ✓
- REST `Xion\DomainException` → JSON envelope, catalog HTTP status (#314 holds) ✓
- HTML `Xion\DomainException` → `domain-error.html` with `{{errorCode}}` / `{{errorMessage}}` substituted (#314 holds) ✓

The error.log captured the `\Throwable` with full exception detail (file + line); the response stayed clean. FT7's separation of concerns survives the `APP_DEBUG=0` flip.

### 4. Banner / version leaks

Every response carried both `X-Powered-By: PHP/8.4.21` and `Server: Apache/2.4.67 (Debian)`. `php -i` confirmed `expose_php => On => On`. Apache's default `ServerTokens` is `Full` in the Debian image.

**Finding (F-2)**: `expose_php = On` leaks the exact PHP version.

**Finding (F-3)**: Apache `ServerTokens Full` + `ServerSignature On` defaults leak Apache version and OS.

### 5. Image audit

`docker compose exec app composer show -i` listed: `phpunit`, `phan`, `phan/tolerant-php-parser`, `phan/var_representation_polyfill`, `friendsofphp/php-cs-fixer`, `phpunit/php-code-coverage`, `phpunit/php-file-iterator`, `phpunit/php-invoker`, etc. All dev-only packages, all running inside the production container.

Tracing the cause:

- `Dockerfile` line 26: `RUN composer install --no-interaction --prefer-dist --no-progress` — no `--no-dev`.
- `compose.yaml` `command:` — `composer install --no-interaction --prefer-dist` — no `--no-dev` either, runs on every container start.

`.dockerignore` excludes `.git`, `vendor`, `log`, `view/compile`, `data/*.db*`, `.env*` from the image build (so the image itself does not pick up host secrets), but does **not** exclude `tests/`, `docs/`, `tools/`, ADR markdown.

**Finding (F-1)**: Production image carries dev composer packages because `composer install` runs without `--no-dev` at both Dockerfile build time and compose runtime.

### 6. Log surface

Triggered the probe `\Throwable`, `DomainException`, plus 404 requests. Inspecting the logs:

```
$ ls log/
access-2026-05-22.log
error-2026-05-22.log

$ grep -c ACCESS log/access-2026-05-22.log
88

$ grep -c 'no-such\|NOT-FOUND' log/access-2026-05-22.log
0

$ cat log/error-2026-05-22.log
# two entries — both \Throwable cases, with full exception detail
# zero entries for DomainException, zero entries for 404
```

The 404 requests produced **no access log entry**. Tracing: access logging happens in `ControllerBase::run()` (lines 188–194); the dispatcher's pre-`run()` paths (`notFoundResponse`, `outputJsonFailure` for METHOD-NOT-ALLOWED / ROUTE-CONFLICT) throw `HttpTermination` before `run()` is called. This is the same shape as the FT7 F-6 decoration trap, but in the access-log layer.

**Finding (F-4)**: Pre-dispatch 404 / 405 / ROUTE-CONFLICT responses are invisible in `log/access-*.log`. Production operators cannot see 404 spam.

`Monolog\Handler\RotatingFileHandler` is correctly used in `class/xion/Log.php` with 60-day retention for access/error and 100-day retention for info. Rotation is *daily* — no in-day size cap. The path itself is hard-coded.

**Finding (F-8)**: `LOG_PATH` (`DIR_ROOT . 'log/'`) is hard-coded, no `NENE_LOG_PATH` env override.

### 7. PHP opcache defaults

`php -i` under prod env still shows `opcache.validate_timestamps = On`, which causes PHP to `stat()` every included file on every request. The conventional production setting is `0` (or a larger `revalidate_freq`).

**Finding (F-5)**: opcache is configured for development-style behavior. Performance footprint only — no correctness issue.

### 8. Documentation cross-check

Searched `docs/` for any "production" / "deployment" guide. None found. The env-var matrix is documented per-variable in `ini/xSystemIni.php` PHPDoc, but there is no single page that says "to deploy this to production, set X / Y / Z."

**Finding (F-6)**: No `docs/development/production-deployment.md` exists.

### 9. DomainException logging

Verified that `Xion\DomainException` (`TODO-NOT-FOUND` in the probe) produces no entry in `log/error-*.log`. The catch path in `htdocs/index.php` (lines 42–55, modified by #314) emits the envelope/template but does not call `Xion\Log`. This is consistent with treating domain failures as expected outcomes, not server errors — but it's undocumented.

**Finding (F-9)**: `DomainException` is intentionally not logged (the catch path has no `Log` call). Worth a sentence in `docs/development/error-rendering.md` so operators don't waste time chasing "missing" log lines.

## Results

| Scenario                                                                       | Expectation                                            | Actual                                                                                | Status   |
| ------------------------------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------------------------------------- | -------- |
| `APP_ENV=production` alone flips `APP_DEBUG` + `SESSION_COOKIE_SECURE`         | yes                                                    | yes (per `ini/xSystemIni.php:82-87, 100-105`)                                          | Pass     |
| `Set-Cookie` carries `Secure` under production env                             | yes                                                    | yes (`secure` present)                                                                | Pass     |
| `APP_DEBUG=0` hides exception messages in HTTP response body                   | yes (`INTERNAL-ERROR` envelope, no message)            | yes (catalog message only)                                                            | Pass     |
| FT7 #312 (404 Accept negotiation) survives `APP_DEBUG=0`                       | yes                                                    | yes                                                                                   | Pass     |
| FT7 #313 (Throwable → envelope/template) survives `APP_DEBUG=0`                | yes                                                    | yes                                                                                   | Pass     |
| FT7 #314 (HTML DomainException → template) survives `APP_DEBUG=0`              | yes                                                    | yes                                                                                   | Pass     |
| `X-Powered-By` header is absent in production                                  | absent                                                 | present (`PHP/8.4.21`)                                                                | Blocked  |
| `Server` header is `Apache` only (no version)                                  | minimal                                                | full (`Apache/2.4.67 (Debian)`)                                                       | Blocked  |
| Production image free of dev composer packages                                 | yes                                                    | phpunit / phan / cs-fixer all present                                                 | Blocked  |
| 404 requests appear in access log                                              | yes                                                    | no entries for 404                                                                    | Blocked  |
| `\Throwable` is logged with full detail server-side                            | yes                                                    | yes                                                                                   | Pass     |
| `DomainException` is logged                                                    | (intentional skip)                                     | not logged (intentional, undocumented)                                                | Partial  |
| Logs rotate                                                                    | yes                                                    | yes (daily, 60/100-day retention)                                                     | Pass     |
| Log path is configurable via env                                               | yes                                                    | no — hard-coded                                                                       | Partial  |
| `opcache.validate_timestamps = 0` under production env                         | yes                                                    | `On` (PHP default)                                                                    | Partial  |
| Documented production deployment guide                                         | yes                                                    | no doc                                                                                | Blocked  |

## Friction Summary

| ID  | Location                                                                    | Severity | Kind             | Decision        |
| --- | --------------------------------------------------------------------------- | -------- | ---------------- | --------------- |
| F-1 | `Dockerfile` + `compose.yaml` (`composer install` without `--no-dev`)        | medium   | feature-gap      | fix-in-framework |
| F-2 | `Dockerfile` (PHP `expose_php` default)                                     | medium   | feature-gap      | fix-in-framework |
| F-3 | `Dockerfile` (Apache `ServerTokens` default)                                | medium   | feature-gap      | fix-in-framework |
| F-4 | `class/xion/Dispatcher.php` (pre-dispatch 404/405 skip access log)           | medium   | feature-gap      | fix-in-framework |
| F-5 | `Dockerfile` (PHP `opcache.validate_timestamps` default)                    | low      | feature-gap      | document        |
| F-6 | (no doc)                                                                    | low      | docs-gap         | document        |
| F-7 | `ini/xSystemIni.php:82-87, 100-105` (conditional defaults on `APP_ENV`)     | low      | docs-gap         | document        |
| F-8 | `ini/xSystemIni.php:197` (`LOG_PATH` hard-coded)                            | low      | feature-gap      | fix-in-framework |
| F-9 | `htdocs/index.php` (DomainException catch silently un-logged)               | low      | informational    | document        |

## Recommendations

### Immediate (documentation only)

1. **F-6 / F-7 — Add `docs/development/production-deployment.md`.** One page that lists the env vars that matter (`NENE_APP_ENV`, `NENE_APP_DEBUG`, `NENE_SESSION_SECURE`, `NENE_LOGOUT_URI`, the upcoming `NENE_LOG_PATH`), notes that `APP_ENV=production` alone flips the safe defaults, and includes the Secure-cookie-over-HTTP caveat from this trial.
2. **F-5 — Production opcache note.** Add a section to the production-deployment doc recommending `opcache.validate_timestamps=0` (or a longer `revalidate_freq`) for deployed images, even if the framework's `Dockerfile` keeps the dev-friendly default for the bundled image.
3. **F-9 — DomainException is not server-logged.** Note this in `docs/development/error-rendering.md` so operators don't chase missing log lines.

### Suggested (small framework or template change)

1. **F-1 — Use `--no-dev` for the production image.** Update `Dockerfile` `composer install` to `--no-dev`; update `compose.yaml` `command:` to do the same, or split dev vs prod compose configurations. Keep an opt-in for dev environments (the framework ships *as* a dev environment, so the default needs care).
2. **F-2 / F-3 — Suppress server banners.** Add a `docker/php-conf-d/` drop-in (`expose_php = Off`) plus an Apache `conf-available` snippet (`ServerTokens Prod`, `ServerSignature Off`) to the Docker image, then `a2enconf` them in the `Dockerfile`.
3. **F-4 — Emit ACCESS log on pre-dispatch failures.** In `Dispatcher::dispatch()`, call `Xion\Log::getInstance('access')` before throwing `HttpTermination` for the 404 / 405 / ROUTE-CONFLICT paths. Same as the FT7 F-6 decoration trap mitigation — fix at the dispatcher boundary, not at the `run()` tail.
4. **F-8 — `NENE_LOG_PATH` env override.** Extend `ini/xSystemIni.php` (line 197) to read `$getEnv('NENE_LOG_PATH', DIR_ROOT . 'log/')` so production deployers can point logs at `/var/log/<app>/` or a Docker volume.

### Trade-offs (needs ADR or discussion)

1. **F-1 — Single image vs split images.** A `--no-dev` production image is the right shape, but NeNe also ships *as* a dev environment that runs `composer test` and `composer test:http` in CI. A clean split would mean either (a) a separate `compose.prod.yaml` overlay, (b) two Dockerfile stages, or (c) a build arg `NENE_NO_DEV=1`. The right call depends on whether NeNe expects users to deploy with the bundled image or to write their own. The trial does **not** prescribe the split — only the symptom.

## Aftermath

- Probe controller (`Ft8probeController.php`) stays inside the clone; not committed back.
- One follow-up Issue per actionable F-N filed in `hideyukiMORI/NeNe`, linked from #326.
- All Issues to be closed by merged PR before FT9 starts (per ADR-0002 cadence).
