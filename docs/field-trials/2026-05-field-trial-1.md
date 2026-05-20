# Field Trial 1 — baseline (bookmarklog clone)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`.

## Date

2026-05-20

## Baseline

- NeNe ref: `2b28eef` (Merge PR #221, the commit that introduced the field trial methodology)
- Clone path: `../NeNe-FT/ft1-bookmarklog/`
- Trial host: WSL 2 (stock Ubuntu 22.04 image) on Windows, no native PHP, Docker Desktop providing the runtime
- PHP: 8.4.21 (from the `php:8.4-apache` container)
- Database: MySQL 8.4 (Docker Compose default) with SQLite parity confirmed
- Other tooling: Composer 2.9.8, PHPUnit 10.5.63, curl 8

## Goal

Validate the field trial methodology that PR #221 just introduced, by running the very first trial against the same `main`. The intended scope was a small two-entity REST service (Bookmark + Tag, with `bookmark_tags` junction) to exercise the M:N + transaction surfaces. The actual scope shifted to **baseline verification only** after the baseline phase produced enough findings to fill its own trial.

The Bookmark + Tag implementation is now planned as FT2 against the cleaner baseline that FT1 produced.

## Service Built

None. FT1 ended at the baseline phase, before any new entity, controller, or mapper was written. The trial clone was used as a runtime probe.

## Steps Taken

### 1. Trial clone created

```sh
mkdir -p ../NeNe-FT
git clone git@github.com:hideyukiMORI/NeNe.git ../NeNe-FT/ft1-bookmarklog
```

Clone landed cleanly on `2b28eef`. No friction.

### 2. Composer install via host shell

Attempted `composer install` directly in the trial clone. The host's Composer (`/mnt/c/ProgramData/ComposerSetup/bin/composer`) called a PHP binary that did not exist in WSL:

```text
$ composer install
/mnt/c/ProgramData/ComposerSetup/bin/composer: 14: php: not found
```

**Finding (F-0a)**: The host did not provide a PHP runtime to WSL. Ubuntu 22.04's default apt repository tops out at PHP 8.1, while NeNe targets PHP 8.4, so an apt-based install was not a practical path on this image. The natural fallback is Docker.

### 3. Composer install via Docker Compose

Fell back to `docker compose run --rm app composer install`. It failed because Docker Desktop's WSL integration was not enabled for this distro:

```text
$ docker compose run --rm app composer install
The command 'docker' could not be found in this WSL 2 distro.
We recommend to activate the WSL integration in Docker Desktop settings.
```

**Finding (F-0b)**: Docker Desktop was installed on the host but the WSL integration toggle had not been flipped for the active distro. Enabling it required a single UI action, but the NeNe Docker Quick Start did not signal this prerequisite to a fresh WSL contributor.

### 4. Docker Desktop integration enabled, command still missing

After enabling integration in Docker Desktop, the `docker` command still did not resolve in the existing shell session. The CLI binary was present at `/mnt/wsl/docker-desktop/cli-tools/usr/bin/docker`, just not on `PATH`. Adding it to `PATH` exposed a second issue:

```text
permission denied while trying to connect to the docker API at unix:///var/run/docker.sock
```

The user was not in the `docker` group. After `sudo usermod -aG docker $USER` and a WSL restart, `docker ps` finally returned a header.

**Finding (F-0c)**: Enabling Docker Desktop WSL integration was a necessary but not sufficient step. The user also needed to be in the `docker` group and to restart their WSL session.

### 5. Composer install via Docker (successful)

```sh
docker compose run --rm app composer install --no-progress
```

This worked, but emitted a Git warning on every invocation:

```text
fatal: detected dubious ownership in repository at '/var/www/html'
```

**Finding (F-5)**: The host bind mount (`.:/var/www/html`) is owned by the host uid; container `git` runs as `root`. Git 2.35.2+ flags this and prints a warning on every command. No execution impact, but noisy.

### 6. Unit tests passed

```sh
docker compose exec -T app composer test
```

→ `43 / 43 (100%) OK (43 tests, 125 assertions)`. The vendor directory is a named volume (not a host bind), so unit testing must run inside the container — consistent with the existing Docker workflow.

### 7. Health check

```sh
curl -sS http://localhost:8080/health
```

→ `{"Result":true,"Data":{"healthStatus":"ok","api":true,"database":true,"schema":true,"environment":"development","databaseType":"MySQL"}}`. The Docker stack and the MySQL schema were healthy.

### 8. HTTP smoke tests, first attempt

```sh
docker compose exec -T app composer test:http
```

→ `21 / 21 (100%) Skipped`. Looked green at first glance.

**Finding (F-3)**: PHPUnit prints `OK, but some tests were skipped!` even when every single test in the suite was skipped. A new contributor reads "OK" and assumes the HTTP runtime is verified. It is not.

**Finding (F-4)**: The skip condition is `NENE_HTTP_BASE_URL` being unset. That variable name appears in `docs/development/testing.md` lines 78/87, but does not appear in `.env.example` or `README.md`, and the testing doc only describes the host-side URL (`http://localhost:8080`), not the in-container URL (`http://localhost:80`) needed when tests run via `docker compose exec`. (Initial Issue framing exaggerated this gap; see #226 for the corrected scope.)

### 9. HTTP smoke tests with env set

```sh
docker compose exec -T -e NENE_HTTP_BASE_URL=http://localhost:80 app composer test:http
```

→ **20 tests errored** with the same PHP fatal:

```text
Fatal error: Method Nene\Xion\PdoConnection::__destruct() cannot declare a return type
in /var/www/html/class/xion/PdoConnection.php on line 87
```

Followed by reproduction directly via curl:

```sh
curl -sS -X POST -H 'Content-Type: application/json' \
  -d '{"user":"admin","password":"admin"}' http://localhost:8080/session/login
```

→ The same fatal as an HTML error page.

**Finding (F-1)**: `class/xion/PdoConnection.php:87` declared `__destruct(): void`. PHP forbids any return type (including `void`) on `__destruct()`. Every request path that exercised `PdoConnection` produced a fatal. `/health` happens to use a different path that does not load the class. Introduced in PR #213 and never caught by CI because:

**Finding (F-2)**: `.github/workflows/tests.yml` had a step literally named "Confirm HTTP tests skip without runtime" and **no job that ever starts a runtime**. By design, unit tests never instantiate `PdoConnection` against a real PDO, and the HTTP suite was confirmed to *skip* in CI. The combination meant `PdoConnection` had effectively zero runtime coverage in CI.

### 10. F-1 hotfix loop

Created Issue #222 → branch `fix/222-pdoconnection-destruct-return-type` → 1-line removal of `: void` → PR #223 → CI green → merged → trial clone pulled. End-to-end ~30 minutes.

After the fix:

- `POST /session/login` returned proper JSON (`{"errorCode":"LOGIN-FAILED",...}`, which is the intended app-level response when credentials are wrong).
- `composer test:http` ran 20 of 21 tests for 211 assertions. The remaining skip was the optional `HttpErrorExposureTest` which requires a separate base URL.

### 11. Follow-up Issues filed and closed

| Finding | Issue | Resolution PR | Merged |
| --- | --- | --- | --- |
| F-1 | #222 | #223 | yes |
| F-2 | #224 | #230 | yes |
| F-3 | #225 | #229 | yes |
| F-4 | #226 | #228 | yes |
| F-5 | #227 | #231 | yes |

F-0a/F-0b/F-0c were not filed as Issues because they relate to the contributor's WSL host configuration rather than NeNe itself. They are recorded here for future reference and may be folded into a `docs/development/wsl.md` note if a second trial confirms the same pattern.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `git clone` of NeNe `main` | clean checkout | clean | Pass |
| `composer install` via host shell | install dependencies | failed (no host PHP) | F-0a |
| `docker compose run app composer install` | install via Docker | failed (no Docker access) | F-0b / F-0c |
| `docker compose run app composer install` (after enabling integration + group) | install | clean, 58 packages | Pass |
| `composer test` inside container | unit suite green | 43/43 pass, 125 assertions | Pass |
| `curl /health` | runtime OK | 200, MySQL healthy | Pass |
| `composer test:http` without env | skip clearly | 21/21 silently skipped, output reads "OK" | F-3 |
| `composer test:http` with env, pre-fix | runtime suite green | 20 errors, all the same PHP fatal | F-1 |
| `composer test:http` with env, post-fix (#223) | runtime suite green | 20/21 pass, 1 optional skip | Pass |
| CI run on the bug commit | catch the fatal | green, never ran the runtime | F-2 |

## Friction Summary

| ID | Location | Severity | Kind | Decision | Resolution |
| --- | --- | --- | --- | --- | --- |
| F-0a | WSL host PHP availability | high | process-gap | defer (host-side; document later if FT2 confirms) | not filed |
| F-0b | WSL host Docker integration | high | process-gap | defer (host-side) | not filed |
| F-0c | Linux `docker` group membership | high | process-gap | defer (host-side) | not filed |
| F-1 | `class/xion/PdoConnection.php:87` | high | feature-gap (real bug) | fix-in-framework | #223 |
| F-2 | `.github/workflows/tests.yml` (no runtime job) | high | process-gap | fix-in-framework | #230 |
| F-3 | `composer test:http` output | medium | feature-gap | fix-in-framework (preflight banner) | #229 |
| F-4 | docs gap around `NENE_HTTP_BASE_URL` | medium | docs-gap | document | #228 |
| F-5 | container `dubious ownership` warning | low | docs-gap | fix-in-framework (Dockerfile) | #231 |

## Recommendations

### Immediate (documentation only)

None remaining. The doc gap (F-4) was closed in #228.

### Suggested (small framework or template change)

None remaining. F-1 (#223), F-3 (#229), F-5 (#231) are all merged.

### Trade-offs (needs ADR or discussion)

None surfaced. The Docker Compose vs. PHP built-in server choice for the runtime smoke job (F-2) was decided in #230 in favor of Docker Compose to keep CI exercise the same MySQL path as the Quick Start.

## Overall Impression

The methodology paid for itself on its first run, in two distinct ways:

1. **It caught a real runtime fatal that CI had been declaring green.** F-1 was a PHP-spec-illegal `: void` on `__destruct()` that nothing in the existing test surface could detect. The unit suite did not instantiate `PdoConnection`; the HTTP suite was structurally skipping in CI. A few hours of methodology setup ended in a 1-line fix to `main` and a CI structure change that prevents the entire class of bug from re-occurring.

2. **It exposed a structural gap (F-2) that mattered more than the bug it produced.** The hotfix for F-1 is a single line; the CI change in #230 is the durable improvement. The trial's most valuable output was the CI job, not the bug fix.

Less positive aspects worth recording:

- Environment friction (F-0 series) consumed a meaningful fraction of the session. On WSL with a stock Ubuntu image and no native PHP, the Docker route is the only practical one, but reaching it required three unrelated host-side steps. None of these are NeNe's fault, but the Quick Start does not signpost them. A short WSL prerequisite paragraph in `docs/development/docker.md` would help, and FT2 will confirm whether that is worth doing.
- The Bookmark + Tag implementation was the original scope. We never reached it. That is fine for FT1 — the unplanned baseline issues were more important — but the M:N + transaction surfaces that motivated the trial remain unexercised. FT2 will pick that up against a cleaner baseline.
- The boundary between "FT-only findings" and "findings any contributor would have hit immediately" is not crisp. F-1 was a real bug that the very first `curl /session/login` would have surfaced. Calling it an FT finding is true but slightly generous; the structured framing is the FT contribution, not the discovery.

## Follow-up Issues

All issues filed from this trial are closed via merged PRs. See the Friction Summary table for the mapping.

## Reminder

This report omits secrets, raw API keys, production endpoints, and confidential prompts.
