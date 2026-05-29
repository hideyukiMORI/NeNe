<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EmailSuppression;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailSuppression.
 */
final class EmailSuppressionTest extends TestCase
{
    private PDO $db;
    private EmailSuppression $sup;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE email_suppressions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         VARCHAR(255) NOT NULL,
                reason        VARCHAR(20)  NOT NULL,
                suppressed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (email)
            )
        ');
        $this->sup = new EmailSuppression($this->db);
    }

    public function testSuppressAndCheck(): void
    {
        $this->sup->suppress('user@example.com', EmailSuppression::REASON_BOUNCE);
        $this->assertTrue($this->sup->isSuppressed('user@example.com'));
        $this->assertSame('bounce', $this->sup->reasonFor('user@example.com'));
    }

    public function testCaseInsensitiveAndTrimmed(): void
    {
        $this->sup->suppress('  USER@Example.COM ');
        $this->assertTrue($this->sup->isSuppressed('user@example.com'));
        $this->assertTrue($this->sup->isSuppressed('User@Example.com'));
    }

    public function testDefaultReasonIsManual(): void
    {
        $this->sup->suppress('user@example.com');
        $this->assertSame('manual', $this->sup->reasonFor('user@example.com'));
    }

    public function testSuppressIsIdempotentAndUpdatesReason(): void
    {
        $this->sup->suppress('user@example.com', EmailSuppression::REASON_BOUNCE);
        $this->sup->suppress('user@example.com', EmailSuppression::REASON_COMPLAINT);
        $this->assertSame('complaint', $this->sup->reasonFor('user@example.com'));
        $this->assertCount(1, $this->sup->all());
    }

    public function testNotSuppressedReturnsNull(): void
    {
        $this->assertFalse($this->sup->isSuppressed('nobody@example.com'));
        $this->assertNull($this->sup->reasonFor('nobody@example.com'));
    }

    public function testRelease(): void
    {
        $this->sup->suppress('user@example.com');
        $this->sup->release('USER@example.com'); // case-insensitive
        $this->assertFalse($this->sup->isSuppressed('user@example.com'));
    }

    public function testReleaseMissingIsNoop(): void
    {
        $this->sup->release('ghost@example.com');
        $this->assertSame([], $this->sup->all());
    }

    public function testFilterKeepsDeliverablePreservingOrderAndCasing(): void
    {
        $this->sup->suppress('user@example.com', EmailSuppression::REASON_BOUNCE);
        $kept = $this->sup->filter(['A@x.com', 'USER@example.com', 'b@x.com']);
        $this->assertSame(['A@x.com', 'b@x.com'], $kept); // suppressed dropped, casing kept
    }

    public function testFilterEmptyList(): void
    {
        $this->assertSame([], $this->sup->filter([]));
    }

    public function testFilterDropsBlankEntries(): void
    {
        $kept = $this->sup->filter(['  ', 'a@x.com']);
        $this->assertSame(['a@x.com'], $kept);
    }

    public function testAllFilteredByReason(): void
    {
        $this->sup->suppress('a@x.com', EmailSuppression::REASON_BOUNCE);
        $this->sup->suppress('b@x.com', EmailSuppression::REASON_COMPLAINT);
        $this->sup->suppress('c@x.com', EmailSuppression::REASON_BOUNCE);
        $this->assertCount(2, $this->sup->all(EmailSuppression::REASON_BOUNCE));
        $this->assertCount(3, $this->sup->all());
    }

    public function testSuppressRejectsUnknownReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sup->suppress('user@example.com', 'explosion');
    }

    public function testSuppressRejectsEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sup->suppress('   ');
    }

    public function testAllRejectsUnknownReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sup->all('bogus');
    }
}
