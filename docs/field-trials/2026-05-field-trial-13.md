# Field Trial 13 — email-sending (Symfony Mailer + mailpit dev catcher + `NENE_MAIL_*` env)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #374.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT12 main (`UploadedFile` / `sendFile` / upload env + drift / multipart docs all merged).
- Clone path: `/home/xi/github/NeNe-FT/ft13-email-sending/`
- Host ports: app=8093, mysql=3320, mailpit web=8025 (new), mailpit smtp=1025 (new)
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 66 / 66 (FT12 leaves us at 66) |
| `composer test:http` | 23 / 23, 219 assertions, 1 expected skip |

## Goal

FT1–FT12 did not exercise mail. `grep -rnE 'PHPMailer|Symfony.\\Mailer|SwiftMailer|mail\(|smtp_|mailer' --include="*.php"` returned **zero** matches. The trial walks a viable mail stack end-to-end (dep choice → helper → env wiring → dev catcher → live verify), records what is missing, and ships an ADR + helper PR before FT14.

## Service Built

- New framework helpers: `Nene\Xion\MailMessage` (immutable value object: to / subject / body / from / contentType), `Nene\Xion\Mailer` (singleton wrapper around `Symfony\Component\Mailer\Mailer`).
- New runtime dep: `symfony/mailer:^6.4` (+ transitive `symfony/mime`, `egulias/email-validator`).
- New compose service: `mailpit` (web 8025, smtp 1025).
- New env vars: `NENE_MAIL_DSN` (default `smtp://mailpit:1025` in compose, `null://null` in tests), `NENE_MAIL_FROM` (default `noreply@nene.local`).
- New unit tests: `tests/Unit/Xion/MailerTest.php` (4 cases with in-memory recording transport).
- New ADR: `docs/adr/0006-symfony-mailer-as-mail-dep.md`.

## Steps Taken

### 1. Cold survey

`grep -rnE 'PHPMailer|Symfony.\\Mailer|SwiftMailer|mail\(|smtp_|mailer'` across the repo returned **zero** matches. No `Mailer` class in `class/xion/`, no `mail()` calls, no SMTP env vars. Identical shape to FT12's file-upload survey: a completely empty surface.

### 2. Dep choice (ADR-0006)

Two candidates: PHPMailer and Symfony Mailer.

| Aspect | PHPMailer | Symfony Mailer |
| --- | --- | --- |
| API | procedural (setters + `send()`) | message + transport abstraction |
| Transport swap | manual (per-build) | DSN-based (`null://`, `smtp://`, `sendmail://`, custom) |
| Test mode | none built-in | `null://null` transport ships |
| Alignment | none | `symfony/yaml` already in tree |

Adopted Symfony Mailer. The DSN config maps cleanly to NeNe's `NENE_*` env-var pattern, and the `null://null` default means tests never accidentally hit a real SMTP server. ADR-0006 records the decision and the trade-off (three transitive packages instead of one).

### 3. Helper design

```php
new MailMessage(
    to: 'user@example.com',
    subject: 'Hello',
    body: 'plain text body',
    // from: optional — falls back to NENE_MAIL_FROM
    // contentType: 'text/plain' (default) or 'text/html'
)
```

```php
Mailer::getInstance()->send($message);
```

The `Mailer` singleton reads `NENE_MAIL_DSN` lazily on first `getInstance()` and caches the transport. Tests call `Mailer::reset()` in setUp/tearDown and `Mailer::setInstance(new SymfonyMailer(<recording-transport>), ...)` to inject an in-memory transport. The `*Action()` / `*Rest` handler integration is a one-line `Mailer::getInstance()->send(new MailMessage(...))`.

### 4. compose + env wiring

`compose.yaml` gained a `mailpit` service and two env vars:

```yaml
services:
  app:
    environment:
      NENE_MAIL_DSN: "${NENE_MAIL_DSN:-smtp://mailpit:1025}"
      NENE_MAIL_FROM: "${NENE_MAIL_FROM:-noreply@nene.local}"
  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "${NENE_MAILPIT_WEB_PORT:-8025}:8025"
```

mailpit catches every outgoing message in dev; the operator opens `http://localhost:8025/` to inspect.

### 5. Live verify

Probe controller `Ft13probeController` sends a plain message and an HTML message:

```bash
$ curl -sS http://127.0.0.1:8093/ft13probe/send
{"Result":true,"Data":{"status":"success","errorCode":"","sent":true}}

$ curl -sS http://127.0.0.1:8093/ft13probe/html
{"Result":true,"Data":{"status":"success","errorCode":"","sent":true}}

$ curl -sS http://127.0.0.1:8025/api/v1/messages | head -c 80
{"total":2,"unread":2,"count":2,"messages_count":2,"messages_unread":2,"start":0
```

Both messages arrived in mailpit with correct `From: noreply@nene.local`, `To`, `Subject`, plain / HTML body. Round-trip works end-to-end.

### 6. Unit tests

`tests/Unit/Xion/MailerTest.php` adds 4 cases:

- `testSendDispatchesMessageThroughInjectedTransport`
- `testHtmlContentTypeUsesHtmlBody`
- `testExplicitFromOverridesDefault`
- `testGetInstanceUsesNullTransportByDefaultEnv`

The recording transport implements `TransportInterface` directly because Symfony's bundled `NullTransport` is `final` (small surface: ~15 lines).

### 7. Friction inventory

The trial surfaced more "informational" than "fix-in-framework" findings — most of the design landed cleanly. The notable items:

- **F-1** (informational): `NullTransport` is `final`; tests roll their own `TransportInterface` recorder. Documented in `email-sending.md`.
- **F-2** (informational): `MailMessage` is single-recipient by design; multi-recipient / cc / bcc / attachments are out-of-scope for FT13. Documented in PHPDoc + `email-sending.md`.
- **F-3** (medium): `composer audit` reports pre-existing `symfony/yaml` CVEs (CVE-2026-45305, CVE-2026-45133). Unrelated to FT13 but visible in the audit output of every PR that touches composer. Filed as a separate follow-up; not part of FT13's PR scope.
- **F-4** (medium docs): mailpit / production-SMTP convention needs `docs/development/email-sending.md` + production-deployment matrix update + docker.md cross-link.
- **F-5** (informational): Compose default (`smtp://mailpit:1025`) differs from test default (`null://null`); documented in `email-sending.md`.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| `grep -rnE 'mailer|smtp_|PHPMailer'` returns 0 matches at baseline | yes | yes | Pass |
| Dep choice ADR drafted | yes | ADR-0006 Accepted | Pass |
| `Mailer::getInstance()->send(new MailMessage(...))` works in dev | yes | yes (mailpit caught) | Pass |
| Tests do not accidentally hit a real SMTP server | yes | `null://null` default + in-memory recorder | Pass |
| Production deploy can swap to real SMTP via env | yes | `NENE_MAIL_DSN=smtp://relay.example.com:587` works (out-of-band check) | Pass |
| `composer test` stays green | yes | 70 / 70 (66 + 4 new MailerTest) | Pass |
| `composer test:http` stays green | yes | 23 / 23, 1 expected skip | Pass |
| `docs/development/email-sending.md` exists | yes | not yet (separate doc PR) | Partial |
| `docs/adr/0006-...` exists | yes | drafted in the feat PR | Pass |
| mailpit visible in dev | yes | yes (`http://localhost:8025/`) | Pass |

## Friction Summary

| ID  | Location                                      | Severity | Kind             | Decision         |
| --- | --------------------------------------------- | -------- | ---------------- | ---------------- |
| F-1 | (test pattern, ad-hoc)                        | low      | informational    | document         |
| F-2 | `class/xion/MailMessage.php`                  | low      | informational    | document         |
| F-3 | `composer.lock` (`symfony/yaml`)              | medium   | feature-gap      | fix-in-framework |
| F-4 | `docs/development/`                           | medium   | docs-gap         | document         |
| F-5 | `compose.yaml`                                | low      | informational    | document         |

## Recommendations

### Immediate (small framework change)

1. **Feat PR — ADR-0006 + Mailer + symfony/mailer dep + mailpit service.** ADR + helper + env wiring + unit tests + compose change land together. F-1 / F-2 / F-5 are covered inline (PHPDoc and `compose.yaml` comments).
2. **Docs PR — `docs/development/email-sending.md`.** End-to-end mailer guide: helper API, env-var matrix, dev catcher access, test-mode caveat, production SMTP replacement, security notes (DKIM/SPF/From: handling are operator-side, not framework-side). Touches `production-deployment.md` matrix + `docker.md` (mailpit service link) + `AGENTS.md` Read-First. Optional `docs/review/email-sending.md` checklist if reviewers ask.

### Immediate (separate follow-up)

1. **Bump `symfony/yaml` to a CVE-patched version (F-3).** Pre-existing; surfaced by FT13's `composer audit`. Not blocked on this trial.

### Trade-offs

None blocking. Symfony Mailer pulls three transitive packages where PHPMailer would pull one; ADR-0006 records the choice. Out-of-scope: multi-recipient / cc / bcc / attachments / templated bodies / queued send — future ADRs if needed.

## Aftermath

- Probe controller (`Ft13probeController.php`) stays inside the clone; not committed back.
- Two PRs filed against this report: feat (ADR + helpers + compose) and docs (`email-sending.md` + checklist).
- F-3 (`symfony/yaml` bump) filed as a separate Issue without blocking this trial.
- All in-scope Issues closed before FT14 starts.
