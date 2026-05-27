<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DocumentLock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DocumentLock.
 */
final class DocumentLockTest extends TestCase
{
    private PDO $db;
    private DocumentLock $dl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE document_locks (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                expires_at  DATETIME     NOT NULL,
                acquired_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id)
            )
        ');
        $this->dl = new DocumentLock($this->db);
    }

    // ── acquire ───────────────────────────────────────────────────────────────

    public function testAcquireGrantsLockOnFreeDocument(): void
    {
        $result = $this->dl->acquire('post', '1', 'user-1');
        $this->assertTrue($result);
    }

    public function testAcquireReturnsFalseWhenHeldByOther(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $result = $this->dl->acquire('post', '1', 'user-2');
        $this->assertFalse($result);
    }

    public function testAcquireSucceedsForSameHolder(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $result = $this->dl->acquire('post', '1', 'user-1');
        $this->assertTrue($result);
    }

    public function testAcquireSucceedsOnExpiredLock(): void
    {
        // Insert an expired lock for user-1
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                         VALUES ('post', '99', 'user-1', '{$past}')");

        $result = $this->dl->acquire('post', '99', 'user-2');
        $this->assertTrue($result);
    }

    public function testAcquireLocksAreEntityScoped(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        // Same user-id can lock a different entity
        $result = $this->dl->acquire('page', '1', 'user-2');
        $this->assertTrue($result);
    }

    // ── isLocked ──────────────────────────────────────────────────────────────

    public function testIsLockedReturnsTrueWhenLocked(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $this->assertTrue($this->dl->isLocked('post', '1'));
    }

    public function testIsLockedReturnsFalseWhenFree(): void
    {
        $this->assertFalse($this->dl->isLocked('post', '1'));
    }

    public function testIsLockedReturnsFalseWhenExpired(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                         VALUES ('post', '5', 'user-1', '{$past}')");
        $this->assertFalse($this->dl->isLocked('post', '5'));
    }

    // ── holder ────────────────────────────────────────────────────────────────

    public function testHolderReturnsRecordWhenLocked(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $row = $this->dl->holder('post', '1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('post', $row['entity_type']);
    }

    public function testHolderReturnsNullWhenFree(): void
    {
        $this->assertNull($this->dl->holder('post', '99'));
    }

    public function testHolderReturnsNullWhenExpired(): void
    {
        $past = (new \DateTimeImmutable())->modify('-5 seconds')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                         VALUES ('post', '7', 'user-1', '{$past}')");
        $this->assertNull($this->dl->holder('post', '7'));
    }

    // ── refresh ───────────────────────────────────────────────────────────────

    public function testRefreshExtendsTtlForHolder(): void
    {
        $this->dl->acquire('post', '1', 'user-1', 10);
        $result = $this->dl->refresh('post', '1', 'user-1', 600);
        $this->assertTrue($result);
        $row = $this->dl->holder('post', '1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('2026', $row['expires_at']);
    }

    public function testRefreshReturnsFalseForNonHolder(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $result = $this->dl->refresh('post', '1', 'user-2');
        $this->assertFalse($result);
    }

    public function testRefreshReturnsFalseWhenUnlocked(): void
    {
        $result = $this->dl->refresh('post', '42', 'user-1');
        $this->assertFalse($result);
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseByHolder(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $result = $this->dl->release('post', '1', 'user-1');
        $this->assertTrue($result);
        $this->assertFalse($this->dl->isLocked('post', '1'));
    }

    public function testReleaseByNonHolderFails(): void
    {
        $this->dl->acquire('post', '1', 'user-1');
        $result = $this->dl->release('post', '1', 'user-2');
        $this->assertFalse($result);
        $this->assertTrue($this->dl->isLocked('post', '1'));
    }

    public function testReleaseOnUnlockedReturnsFalse(): void
    {
        $result = $this->dl->release('post', '99', 'user-1');
        $this->assertFalse($result);
    }

    // ── releaseExpired ────────────────────────────────────────────────────────

    public function testReleaseExpiredDeletesExpiredLocks(): void
    {
        $past = (new \DateTimeImmutable())->modify('-2 seconds')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                         VALUES ('post', '10', 'user-1', '{$past}')");
        $this->db->exec("INSERT INTO document_locks (entity_type, entity_id, user_id, expires_at)
                         VALUES ('post', '11', 'user-1', '{$past}')");
        $this->dl->acquire('post', '12', 'user-2', 300); // active lock

        $deleted = $this->dl->releaseExpired();
        $this->assertSame(2, $deleted);
        $this->assertTrue($this->dl->isLocked('post', '12'));
    }

    public function testReleaseExpiredReturnsZeroWhenNoneExpired(): void
    {
        $this->dl->acquire('post', '1', 'user-1', 300);
        $this->assertSame(0, $this->dl->releaseExpired());
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testAcquireThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dl->acquire('', '1', 'user-1');
    }

    public function testAcquireThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dl->acquire('post', '', 'user-1');
    }

    public function testAcquireThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dl->acquire('post', '1', '');
    }
}
