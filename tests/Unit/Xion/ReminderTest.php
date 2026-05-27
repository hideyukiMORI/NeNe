<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\Reminder;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReminderTest extends TestCase
{
    private PDO $pdo;
    private Reminder $rm;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE reminders (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                message     TEXT         NOT NULL,
                remind_at   DATETIME     NOT NULL,
                entity_type VARCHAR(100) NOT NULL DEFAULT \'\',
                entity_id   VARCHAR(255) NOT NULL DEFAULT \'\',
                sent        TINYINT(1)   NOT NULL DEFAULT 0,
                sent_at     DATETIME     NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->rm = new Reminder($this->pdo);
    }

    private const FUTURE = '2099-12-31 12:00:00';
    private const PAST   = '2000-01-01 12:00:00';

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetReturnsId(): void
    {
        $id = $this->rm->set('user-1', 'Review PR', self::FUTURE);
        $this->assertGreaterThan(0, $id);
    }

    public function testSetStoresFields(): void
    {
        $id  = $this->rm->set('user-1', 'Check in', self::FUTURE, 'issue', '42');
        $row = $this->rm->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Check in', $row['message']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(self::FUTURE, $row['remind_at']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('issue', $row['entity_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('42', $row['entity_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['sent']);
    }

    public function testSetThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rm->set('', 'msg', self::FUTURE);
    }

    public function testSetThrowsOnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rm->set('user-1', '', self::FUTURE);
    }

    public function testSetThrowsOnEmptyRemindAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rm->set('user-1', 'msg', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->rm->find(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllForUser(): void
    {
        $this->rm->set('user-1', 'A', self::FUTURE);
        $this->rm->set('user-1', 'B', self::FUTURE);
        $this->rm->set('user-2', 'C', self::FUTURE);

        $rows = $this->rm->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    public function testForUserUnsentOnlyExcludesSent(): void
    {
        $id1 = $this->rm->set('user-1', 'A', self::PAST);
        $this->rm->set('user-1', 'B', self::FUTURE);
        $this->rm->markSent($id1);

        $rows = $this->rm->forUser('user-1', true);
        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]['message']);
    }

    public function testForUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rm->forUser('');
    }

    // ── due ───────────────────────────────────────────────────────────────────

    public function testDueReturnsPastUnsentReminders(): void
    {
        $this->rm->set('user-1', 'Old', self::PAST);
        $this->rm->set('user-1', 'Future', self::FUTURE);

        $due = $this->rm->due();
        $this->assertCount(1, $due);
        $this->assertSame('Old', $due[0]['message']);
    }

    public function testDueExcludesSentReminders(): void
    {
        $id = $this->rm->set('user-1', 'Old', self::PAST);
        $this->rm->markSent($id);

        $this->assertSame([], $this->rm->due());
    }

    public function testDueUsesCustomAsOf(): void
    {
        $this->rm->set('user-1', 'In 2026', '2026-06-01 00:00:00');
        $this->rm->set('user-1', 'In 2030', '2030-01-01 00:00:00');

        $due = $this->rm->due('2027-01-01 00:00:00');
        $this->assertCount(1, $due);
        $this->assertSame('In 2026', $due[0]['message']);
    }

    // ── markSent ──────────────────────────────────────────────────────────────

    public function testMarkSentSetsSentFlag(): void
    {
        $id = $this->rm->set('user-1', 'Check', self::PAST);
        $result = $this->rm->markSent($id);
        $this->assertTrue($result);

        $row = $this->rm->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['sent']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['sent_at']);
    }

    public function testMarkSentReturnsFalseForAlreadySent(): void
    {
        $id = $this->rm->set('user-1', 'Check', self::PAST);
        $this->rm->markSent($id);
        $this->assertFalse($this->rm->markSent($id));
    }

    public function testMarkSentReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rm->markSent(9999));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelDeletesReminder(): void
    {
        $id = $this->rm->set('user-1', 'Cancel me', self::FUTURE);
        $result = $this->rm->cancel($id, 'user-1');
        $this->assertTrue($result);
        $this->assertNull($this->rm->find($id));
    }

    public function testCancelReturnsFalseForWrongOwner(): void
    {
        $id = $this->rm->set('user-1', 'Mine', self::FUTURE);
        $this->assertFalse($this->rm->cancel($id, 'user-2'));
    }

    // ── cancelAll ─────────────────────────────────────────────────────────────

    public function testCancelAllRemovesAllForUser(): void
    {
        $this->rm->set('user-1', 'A', self::FUTURE);
        $this->rm->set('user-1', 'B', self::FUTURE);
        $this->rm->set('user-2', 'C', self::FUTURE);

        $n = $this->rm->cancelAll('user-1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->rm->forUser('user-1'));
        $this->assertCount(1, $this->rm->forUser('user-2'));
    }

    public function testCancelAllThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rm->cancelAll('');
    }

    // ── purgeSent ─────────────────────────────────────────────────────────────

    public function testPurgeSentDeletesOldSentRows(): void
    {
        $id = $this->rm->set('user-1', 'Old', self::PAST);
        $this->rm->markSent($id);

        // purgeSent(0) treats any sent reminder as old enough
        $n = $this->rm->purgeSent(0);
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertNull($this->rm->find($id));
    }

    public function testPurgeSentKeepsUnsentRows(): void
    {
        $this->rm->set('user-1', 'Unsent', self::FUTURE);
        $this->rm->purgeSent(0);
        $this->assertCount(1, $this->rm->forUser('user-1'));
    }
}
