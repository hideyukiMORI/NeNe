<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\MailMessage;
use Nene\Xion\Mailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Mailer is a thin Symfony Mailer wrapper. The unit tests inject an
 * in-memory recording transport so the real SMTP / null transport is
 * never touched. Each test resets the singleton in setUp / tearDown.
 */
final class MailerTest extends TestCase
{
    protected function setUp(): void
    {
        Mailer::reset();
    }

    protected function tearDown(): void
    {
        Mailer::reset();
    }

    public function testSendDispatchesMessageThroughInjectedTransport(): void
    {
        $transport = new RecordingMailTransport();
        Mailer::setInstance(new SymfonyMailer($transport), 'noreply@test.local');

        Mailer::getInstance()->send(new MailMessage(
            to: 'inbox@test.local',
            subject: 'Hello',
            body: 'plain body',
        ));

        self::assertCount(1, $transport->sentEmails);
        $email = $transport->sentEmails[0];
        self::assertSame('inbox@test.local', $email->getTo()[0]->getAddress());
        self::assertSame('Hello', $email->getSubject());
        self::assertSame('plain body', $email->getTextBody());
        self::assertSame('noreply@test.local', $email->getFrom()[0]->getAddress());
    }

    public function testHtmlContentTypeUsesHtmlBody(): void
    {
        $transport = new RecordingMailTransport();
        Mailer::setInstance(new SymfonyMailer($transport), 'noreply@test.local');

        Mailer::getInstance()->send(new MailMessage(
            to: 'inbox@test.local',
            subject: 'Hello',
            body: '<p>html body</p>',
            contentType: 'text/html',
        ));

        $email = $transport->sentEmails[0];
        self::assertSame('<p>html body</p>', $email->getHtmlBody());
        self::assertNull($email->getTextBody());
    }

    public function testExplicitFromOverridesDefault(): void
    {
        $transport = new RecordingMailTransport();
        Mailer::setInstance(new SymfonyMailer($transport), 'default@test.local');

        Mailer::getInstance()->send(new MailMessage(
            to: 'inbox@test.local',
            subject: 'Hello',
            body: 'body',
            from: 'alice@test.local',
        ));

        $email = $transport->sentEmails[0];
        self::assertSame('alice@test.local', $email->getFrom()[0]->getAddress());
    }

    public function testGetInstanceUsesNullTransportByDefaultEnv(): void
    {
        // No setInstance call; relies on environment. The framework's CI
        // runs without NENE_MAIL_DSN so the default `null://null` is used
        // and send() should not throw.
        putenv('NENE_MAIL_DSN=null://null');
        putenv('NENE_MAIL_FROM=noreply@test.local');

        Mailer::getInstance()->send(new MailMessage(
            to: 'inbox@test.local',
            subject: 'Hello',
            body: 'body',
        ));

        // The null transport silently drops, so just reaching here is the assertion.
        self::assertTrue(true);
    }
}

/**
 * In-memory transport that records every Email passed to send().
 * Implements TransportInterface directly because Symfony's NullTransport
 * is `final`. Stays small — the only thing we need is a sentEmails
 * accumulator the tests can read.
 */
class RecordingMailTransport implements TransportInterface
{
    /** @var array<int,Email> */
    public array $sentEmails = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email) {
            $this->sentEmails[] = $message;
        }
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://test';
    }
}
