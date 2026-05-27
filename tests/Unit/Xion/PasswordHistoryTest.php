<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PasswordHistory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PasswordHistory.
 */
final class PasswordHistoryTest extends TestCase
{
    private PDO $db;
    private PasswordHistory $ph;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE password_history (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                hash       VARCHAR(255) NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ph = new PasswordHistory($this->db, depth: 3);
    }

    // ── push ──────────────────────────────────────────────────────────────────

    public function testPushStoresHash(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $this->ph->push('user-1', $hash);
        $this->assertSame(1, $this->ph->count('user-1'));
    }

    public function testPushThrowsOnEmptyHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ph->push('user-1', '');
    }

    public function testPushPrunesOldEntriesBeyondDepth(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->ph->push('user-1', password_hash("pass{$i}", PASSWORD_BCRYPT));
        }
        $this->assertSame(3, $this->ph->count('user-1'));
    }

    public function testPushKeepsNewestHashes(): void
    {
        $hashes = [];
        for ($i = 1; $i <= 5; $i++) {
            $h = password_hash("pass{$i}", PASSWORD_BCRYPT);
            $hashes[] = $h;
            $this->ph->push('user-1', $h);
        }
        // The last 3 hashes should remain
        $stored = $this->ph->recent('user-1');
        $this->assertSame($hashes[4], $stored[0]); // newest
        $this->assertSame($hashes[3], $stored[1]);
        $this->assertSame($hashes[2], $stored[2]); // oldest kept
    }

    // ── wasUsed ───────────────────────────────────────────────────────────────

    public function testWasUsedReturnsTrueForRecentPassword(): void
    {
        $plain = 'MyPassword123';
        $this->ph->push('user-1', password_hash($plain, PASSWORD_BCRYPT));
        $this->assertTrue($this->ph->wasUsed('user-1', $plain));
    }

    public function testWasUsedReturnsFalseForNewPassword(): void
    {
        $this->ph->push('user-1', password_hash('OldPass', PASSWORD_BCRYPT));
        $this->assertFalse($this->ph->wasUsed('user-1', 'NewPass'));
    }

    public function testWasUsedReturnsFalseForEmptyHistory(): void
    {
        $this->assertFalse($this->ph->wasUsed('user-1', 'AnyPass'));
    }

    public function testWasUsedOnlyChecksWithinDepth(): void
    {
        $oldPlain = 'VeryOldPass';
        $this->ph->push('user-1', password_hash($oldPlain, PASSWORD_BCRYPT));
        // Push 3 more to push the old one out
        for ($i = 1; $i <= 3; $i++) {
            $this->ph->push('user-1', password_hash("NewPass{$i}", PASSWORD_BCRYPT));
        }
        $this->assertFalse($this->ph->wasUsed('user-1', $oldPlain));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsEmptyForNewUser(): void
    {
        $this->assertSame([], $this->ph->recent('nobody'));
    }

    public function testRecentReturnsHashesNewestFirst(): void
    {
        $h1 = password_hash('p1', PASSWORD_BCRYPT);
        $h2 = password_hash('p2', PASSWORD_BCRYPT);
        $this->ph->push('user-1', $h1);
        $this->ph->push('user-1', $h2);
        $recent = $this->ph->recent('user-1');
        $this->assertSame($h2, $recent[0]);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroForNewUser(): void
    {
        $this->assertSame(0, $this->ph->count('user-1'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllRows(): void
    {
        $this->ph->push('user-1', password_hash('p1', PASSWORD_BCRYPT));
        $this->ph->push('user-1', password_hash('p2', PASSWORD_BCRYPT));
        $this->assertSame(2, $this->ph->clear('user-1'));
        $this->assertSame(0, $this->ph->count('user-1'));
    }

    public function testClearDoesNotAffectOtherUsers(): void
    {
        $this->ph->push('user-1', password_hash('p1', PASSWORD_BCRYPT));
        $this->ph->push('user-2', password_hash('p2', PASSWORD_BCRYPT));
        $this->ph->clear('user-1');
        $this->assertSame(1, $this->ph->count('user-2'));
    }
}
