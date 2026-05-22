<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Plain immutable description of one outgoing email.
 *
 * The framework helper {@see Mailer::send()} converts a `MailMessage`
 * into a Symfony Mime `Email`. Keep this layer small: it is a value
 * object, not a builder. Multi-recipient / cc / bcc / attachments are
 * intentionally deferred to a future ADR — small-app surface stays
 * "one to: address, one subject, one body".
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $from = null,
        public readonly string $contentType = 'text/plain'
    ) {
    }
}
