<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EmailBounce;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailBounceTest extends TestCase
{
    private PDO $pdo;
    private EmailBounce $eb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE email_bounces (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                email      VARCHAR(255) NOT NULL,
                type       VARCHAR(20)  NOT NULL,
                reason     TEXT         NULL,
                suppressed INTEGER      NOT NULL DEFAULT 1,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->eb = new EmailBounce($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->eb->record('user@example.com', EmailBounce::TYPE_HARD, '550 No such user');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordNormalizesEmailToLowercase(): void
    {
        $this->eb->record('User@EXAMPLE.COM', EmailBounce::TYPE_HARD);
        $this->assertTrue($this->eb->isSuppressed('user@example.com'));
    }

    public function testHardBounceIsSuppressedByDefault(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_HARD);
        $this->assertTrue($this->eb->isSuppressed('user@example.com'));
    }

    public function testSoftBounceIsNotSuppressedByDefault(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_SOFT);
        $this->assertFalse($this->eb->isSuppressed('user@example.com'));
    }

    public function testSoftBounceCanBeSuppressedExplicitly(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_SOFT, null, true);
        $this->assertTrue($this->eb->isSuppressed('user@example.com'));
    }

    public function testComplaintIsSuppressedByDefault(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_COMPLAINT);
        $this->assertTrue($this->eb->isSuppressed('user@example.com'));
    }

    public function testRecordThrowsOnEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eb->record('', EmailBounce::TYPE_HARD);
    }

    public function testRecordThrowsOnInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eb->record('user@example.com', 'invalid');
    }

    // ── recordComplaint ───────────────────────────────────────────────────────

    public function testRecordComplaintCreatesComplaintRecord(): void
    {
        $this->eb->recordComplaint('user@example.com', 'spam report');
        $records = $this->eb->forEmail('user@example.com');
        $this->assertCount(1, $records);
        $this->assertSame(EmailBounce::TYPE_COMPLAINT, $records[0]['type']);
    }

    // ── isSuppressed ──────────────────────────────────────────────────────────

    public function testIsSuppressedFalseForUnknownEmail(): void
    {
        $this->assertFalse($this->eb->isSuppressed('unknown@example.com'));
    }

    public function testIsSuppressedFalseAfterRemoval(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_HARD);
        $this->eb->removeSupression('user@example.com');
        $this->assertFalse($this->eb->isSuppressed('user@example.com'));
    }

    // ── removeSupression ──────────────────────────────────────────────────────

    public function testRemoveSupressionDeletesAllRecordsForEmail(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('user@example.com', EmailBounce::TYPE_SOFT);
        $deleted = $this->eb->removeSupression('user@example.com');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->eb->forEmail('user@example.com'));
    }

    public function testRemoveSupressionReturnsZeroForUnknownEmail(): void
    {
        $this->assertSame(0, $this->eb->removeSupression('unknown@example.com'));
    }

    // ── forEmail ──────────────────────────────────────────────────────────────

    public function testForEmailReturnsAllRecordsForAddress(): void
    {
        $this->eb->record('user@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('user@example.com', EmailBounce::TYPE_SOFT);
        $this->eb->record('other@example.com', EmailBounce::TYPE_HARD);
        $this->assertCount(2, $this->eb->forEmail('user@example.com'));
    }

    // ── suppressedList ────────────────────────────────────────────────────────

    public function testSuppressedListReturnsDistinctEmails(): void
    {
        $this->eb->record('a@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('a@example.com', EmailBounce::TYPE_COMPLAINT); // duplicate
        $this->eb->record('b@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('c@example.com', EmailBounce::TYPE_SOFT); // not suppressed
        $list = $this->eb->suppressedList();
        $this->assertSame(['a@example.com', 'b@example.com'], $list);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAllRecords(): void
    {
        $this->eb->record('a@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('b@example.com', EmailBounce::TYPE_SOFT);
        $this->eb->record('c@example.com', EmailBounce::TYPE_COMPLAINT);
        $this->assertSame(3, $this->eb->count());
    }

    public function testCountFiltersByType(): void
    {
        $this->eb->record('a@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('b@example.com', EmailBounce::TYPE_HARD);
        $this->eb->record('c@example.com', EmailBounce::TYPE_SOFT);
        $this->assertSame(2, $this->eb->count(EmailBounce::TYPE_HARD));
        $this->assertSame(1, $this->eb->count(EmailBounce::TYPE_SOFT));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRecords(): void
    {
        $this->eb->record('a@example.com', EmailBounce::TYPE_HARD);
        $this->pdo->exec(
            "UPDATE email_bounces SET created_at = '2020-01-01 00:00:00'"
        );
        $this->eb->record('b@example.com', EmailBounce::TYPE_HARD);
        $deleted = $this->eb->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }
}
