<?php

declare(strict_types=1);

namespace Nene\Xion;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Thin wrapper around Symfony Mailer (ADR-0006).
 *
 * Reads `NENE_MAIL_DSN` for the transport (default `null://null` so the
 * framework's own tests never accidentally hit a real SMTP server) and
 * `NENE_MAIL_FROM` for the default `From:` address.
 *
 * Build messages via {@see MailMessage} and pass them to `send()`. The
 * underlying transport is initialised lazily, so unit tests that never
 * call `send()` do not pay any cost. The singleton is reset-friendly
 * (`reset()` is used by the test suite to swap transports).
 */
final class Mailer
{
    private static ?self $instance = null;

    private MailerInterface $mailer;
    private string $defaultFrom;

    private function __construct(MailerInterface $mailer, string $defaultFrom)
    {
        $this->mailer = $mailer;
        $this->defaultFrom = $defaultFrom;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $dsn = (string)(getenv('NENE_MAIL_DSN') ?: 'null://null');
            $from = (string)(getenv('NENE_MAIL_FROM') ?: 'noreply@localhost');
            self::$instance = new self(new SymfonyMailer(Transport::fromDsn($dsn)), $from);
        }
        return self::$instance;
    }

    /**
     * Replace the singleton with an explicit Mailer (used by tests to
     * inject an in-memory transport or null transport, and by
     * `reset()` to clear after a test).
     */
    public static function setInstance(MailerInterface $mailer, string $defaultFrom): void
    {
        self::$instance = new self($mailer, $defaultFrom);
    }

    /**
     * Drop the cached singleton so the next call rebuilds from env.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Send a single message. Throws {@see \Symfony\Component\Mailer\Exception\TransportExceptionInterface}
     * on transport failure — callers wrap in `try/catch` if recovery is
     * required, otherwise the framework's top-level error path emits the
     * `INTERNAL-ERROR` envelope.
     */
    public function send(MailMessage $message): void
    {
        $email = (new Email())
            ->from($message->from ?? $this->defaultFrom)
            ->to($message->to)
            ->subject($message->subject);

        if ($message->contentType === 'text/html') {
            $email->html($message->body);
        } else {
            $email->text($message->body);
        }

        $this->mailer->send($email);
    }
}
