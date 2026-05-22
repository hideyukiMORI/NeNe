# ADR 0006: Symfony Mailer as the Mail Dependency

## Status

Accepted

## Context

NeNe's bundled framework had **no mail surface at all** before Field Trial 13 (`docs/field-trials/2026-05-field-trial-13.md`). A `grep` for any of `PHPMailer`, `Symfony\Mailer`, `SwiftMailer`, `mail(`, `smtp_`, `mailer` returned zero matches across the repo. Any real app built on NeNe (password reset, email verification, contact form, daily digest) would have to choose a mail dependency themselves.

The trial walked the implementation end-to-end and concluded that picking a default for the framework — instead of leaving the choice to every app — is the right shape, in keeping with the existing `monolog/monolog` + `smarty/smarty` pattern (a small, opinionated default that an app can replace if it must).

Two viable options were considered:

| Aspect                  | PHPMailer                                  | Symfony Mailer                                                   |
| ----------------------- | ------------------------------------------ | ---------------------------------------------------------------- |
| API shape               | procedural (setters + `send()`)            | message + transport abstraction                                  |
| Transport swap          | manual (per-build SMTP / sendmail / qmail) | DSN-based: `null://null`, `smtp://...`, `sendmail://default`, ... |
| Test-mode transport     | none built-in                              | `null://null` ships out of the box                               |
| Existing alignment      | none                                       | `symfony/yaml` already in the require-dev tree                   |
| Maintenance posture     | active, single-purpose                     | active, transitively pulled by many Symfony projects             |
| Direct deps             | 1 package                                  | 3 packages (`symfony/mailer`, `symfony/mime`, `egulias/email-validator`) |

The decisive factor was the **DSN-based transport abstraction** plus the bundled `null://null` transport. Both map cleanly to NeNe's existing `NENE_*` env-var override pattern (see ADR-0004, ADR-0005, and the env matrix in `docs/development/production-deployment.md`): operators flip transports via env without touching framework code, and the framework's CI defaults to `null://null` so PHPUnit runs never accidentally hit a real SMTP server.

PHPMailer's single-package footprint is a real virtue, but the test-mode story would require either a custom abstraction or `mail.add_x_header = Off` workarounds on every build. The three-package Symfony dep tree is acceptable for a framework that already pulls Monolog and Smarty.

## Decision

- Add `symfony/mailer:^6.4` as a runtime dependency (`require` in `composer.json`).
- Provide two framework classes:
  - `Nene\Xion\MailMessage` — immutable value object: `to`, `subject`, `body`, optional `from`, optional `contentType` (`text/plain` default).
  - `Nene\Xion\Mailer` — singleton wrapper around `Symfony\Component\Mailer\Mailer`. Reads `NENE_MAIL_DSN` (default `null://null`) and `NENE_MAIL_FROM` (default `noreply@localhost`) lazily on first `getInstance()`. Exposes `setInstance()` / `reset()` for test injection.
- Wire `compose.yaml` with a `mailpit` service (`axllent/mailpit:latest`, web on 8025, SMTP on 1025) and set `NENE_MAIL_DSN` to `smtp://mailpit:1025` by default so `composer install && docker compose up` produces a working dev mail catcher with no extra steps.
- Tests must inject a recording transport via `Mailer::setInstance(...)` after `Mailer::reset()` in `setUp()`. The framework ships an example test (`tests/Unit/Xion/MailerTest.php`) that implements `TransportInterface` directly because `Symfony\Component\Mailer\Transport\NullTransport` is `final`.

The `Mailer::send()` signature is intentionally simple — one recipient, one subject, one body. Multi-recipient / cc / bcc / attachments are **explicitly out of scope** for this ADR; the next real app need that requires them gets its own ADR rather than expanding `MailMessage` opportunistically.

## Consequences

Positive:

- Apps built on NeNe get a working mail story with three lines: `Mailer::getInstance()->send(new MailMessage(to: '...', subject: '...', body: '...'))`.
- The dev mail catcher is the standard mailpit instance familiar from many other PHP projects; reviewers open `http://localhost:8025/` to inspect outgoing mail.
- Production deploys swap transports by setting `NENE_MAIL_DSN=smtp://user:pass@relay.example.com:587` (or `sendmail://default`) without touching framework code.
- CI defaults to `null://null` so tests never accidentally email anyone.

Negative / accepted trade-offs:

- Three transitive packages (`symfony/mailer`, `symfony/mime`, `egulias/email-validator`) instead of one. The framework's "small surface" character takes a small dependency-size hit in exchange for the test-mode story.
- `MailMessage` does not cover multi-recipient / cc / bcc / attachments / Name<email> formatting. Apps that need any of these wrap `Mailer::getInstance()` with their own helper, **or** propose an ADR-0007 that extends `MailMessage`.
- `Symfony\Component\Mailer\Transport\NullTransport` is `final`, so the bundled test recorder cannot extend it. The recorder implements `TransportInterface` directly (15-line surface). Documented in `docs/development/email-sending.md`.

Neutral:

- The new mailpit service is dev-only — production deploys must point `NENE_MAIL_DSN` at a real SMTP relay (or `sendmail://default`). `docs/development/production-deployment.md`'s env matrix lists the contrast explicitly.
- Existing controllers and templates are unaffected. The framework's HTML / REST / CSRF / OpenAPI paths know nothing about mail.
