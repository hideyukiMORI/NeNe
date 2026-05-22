# Email Sending

How to send mail from a NeNe controller (or a CLI script), what the dev mail catcher shows, and what changes for a production deploy. Pair with ADR-0006 (`docs/adr/0006-symfony-mailer-as-mail-dep.md`).

Audience: anyone wiring a password-reset, signup-verification, or notification flow. Trial source: FT13 (`docs/field-trials/2026-05-field-trial-13.md`).

## The pieces

| What | Where |
| --- | --- |
| Build a message | `Nene\Xion\MailMessage` (immutable value object) |
| Send a message | `Nene\Xion\Mailer::getInstance()->send(MailMessage $m)` |
| Test injection | `Mailer::setInstance(MailerInterface, $defaultFrom)` + `Mailer::reset()` |
| Dev mail catcher | `mailpit` service in `compose.yaml`, web UI at `http://localhost:8025/` |
| Dep | `symfony/mailer:^6.4` |
| Env vars | `NENE_MAIL_DSN`, `NENE_MAIL_FROM` |

## Sending a message

```php
use Nene\Xion\MailMessage;
use Nene\Xion\Mailer;

Mailer::getInstance()->send(new MailMessage(
    to: 'user@example.com',
    subject: 'Welcome to NeNe',
    body: "Hi, welcome.\n\nNeNe team",
));
```

For HTML mail, pass `contentType: 'text/html'` and write HTML in `body:`. The `from:` defaults to `NENE_MAIL_FROM`; pass it explicitly to override per-message.

```php
Mailer::getInstance()->send(new MailMessage(
    to: 'user@example.com',
    subject: 'Reset your password',
    body: '<p>Click <a href="...">here</a> to reset.</p>',
    contentType: 'text/html',
    from: 'security@example.com',
));
```

`send()` throws `Symfony\Component\Mailer\Exception\TransportExceptionInterface` on transport failure. The framework's top-level `\Throwable` catch in `htdocs/index.php` turns that into the ADR-0003 `INTERNAL-ERROR` envelope (the underlying error is logged in `log/error-*.log`). Wrap in `try / catch` only when the controller wants to recover (re-render the form with a "could not send mail; please try again" message instead of a 500).

## Environment variables

| Variable | Default in `compose.yaml` | Default in framework code | Production-safe value |
| --- | --- | --- | --- |
| `NENE_MAIL_DSN` | `smtp://mailpit:1025` (dev catcher) | `null://null` (discards) | `smtp://user:pass@relay.example.com:587` or `sendmail://default` |
| `NENE_MAIL_FROM` | `noreply@nene.local` | `noreply@localhost` | The verified sender address (DKIM / SPF) for your domain |

Two layers to be aware of:

1. **Framework default** (read by `Mailer::getInstance()` when the env var is unset): `null://null`. This is what runs in CI — PHPUnit never accidentally hits a real SMTP server.
2. **Compose default** (set in `compose.yaml`): `smtp://mailpit:1025`. The dev catcher is part of `docker compose up`, so the first send from a freshly-cloned dev environment lands in mailpit at `http://localhost:8025/` with no extra configuration.

Override either layer via env in the usual way (host shell env, `.env`, or `compose.prod.yaml`).

## Dev mail catcher (mailpit)

The bundled `mailpit` service catches every outgoing message. Open the web UI to inspect headers, body, and attachments:

```
http://localhost:8025/
```

mailpit accepts SMTP on port 1025 inside the compose network and any auth credential (`MP_SMTP_AUTH_ACCEPT_ANY=1`). It is **dev-only** — production deploys must replace `NENE_MAIL_DSN` with a real SMTP relay (see "Production").

## Testing

`Mailer` is a singleton that reads env on first `getInstance()`. Unit tests inject an in-memory recording transport in `setUp()` and reset in `tearDown()`:

```php
use Nene\Xion\Mailer;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;

protected function setUp(): void
{
    Mailer::reset();
    Mailer::setInstance(new SymfonyMailer(new RecordingMailTransport()), 'noreply@test.local');
}

protected function tearDown(): void
{
    Mailer::reset();
}
```

See `tests/Unit/Xion/MailerTest.php` for the full `RecordingMailTransport` (implements `Symfony\Component\Mailer\Transport\TransportInterface` directly; the bundled `NullTransport` is `final` and cannot be extended).

## Production

For a real deploy, set both env vars on the app container:

```yaml
# compose.prod.yaml overlay (sketch)
services:
  app:
    environment:
      NENE_MAIL_DSN: "smtp://user:pass@relay.example.com:587?encryption=tls"
      NENE_MAIL_FROM: "noreply@example.com"
```

Also acceptable:

- `sendmail://default` if the host provides `/usr/sbin/sendmail`.
- `null://null` for staging-without-mail or for an integration test environment.

Operator-side concerns that the framework does **not** handle:

- DKIM / SPF / DMARC alignment — configured at the relay (or via `NENE_MAIL_FROM` choice).
- Bounce handling.
- Rate-limiting outgoing mail.
- Mail queues / retry on transient failure (out of scope for FT13; future ADR if needed).

The `docs/development/production-deployment.md` env matrix lists `NENE_MAIL_DSN` and `NENE_MAIL_FROM` alongside the other framework env vars.

## What is out of scope (per ADR-0006)

The framework's `MailMessage` is single-recipient by design:

- **Multi-recipient / cc / bcc** — `to:` is a single address string.
- **Attachments** — none.
- **Templated bodies** — pass the rendered string in `body:`; if you want Smarty-rendered email, render the template in the controller and hand the result to `MailMessage`.
- **Queued / batched send** — every `send()` is synchronous.

Each of these is a separate ADR if a real app surfaces the need. The trial that surfaces it will weigh whether `MailMessage` grows arms or a sibling helper appears.

## Related

- `docs/adr/0006-symfony-mailer-as-mail-dep.md` — dep choice rationale.
- `docs/development/production-deployment.md` — full env-var matrix (`NENE_MAIL_DSN` / `NENE_MAIL_FROM` listed).
- `docs/development/docker.md` — the dev compose stack (mailpit service).
- `docs/tutorials/building-a-service.md` § "Send an email" — minimal usage example in the tutorial flow.
- `docs/field-trials/2026-05-field-trial-13.md` — the trial that produced this surface.
- Upstream Symfony Mailer docs: <https://symfony.com/doc/current/mailer.html>
