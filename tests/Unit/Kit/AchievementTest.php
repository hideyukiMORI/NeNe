<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Achievement;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Achievement.
 */
final class AchievementTest extends TestCase
{
    private PDO $db;
    private Achievement $a;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE achievement_defs (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                code   VARCHAR(100) NOT NULL,
                name   VARCHAR(190) NOT NULL DEFAULT \'\',
                target INTEGER      NOT NULL,
                UNIQUE (code)
            )
        ');
        $this->db->exec('
            CREATE TABLE achievement_progress (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     BIGINT       NOT NULL,
                code        VARCHAR(100) NOT NULL,
                progress    INTEGER      NOT NULL DEFAULT 0,
                unlocked    INTEGER      NOT NULL DEFAULT 0,
                unlocked_at DATETIME     NULL,
                UNIQUE (user_id, code)
            )
        ');
        $this->a = new Achievement($this->db);
    }

    public function testAdvanceUnlocksAtTarget(): void
    {
        $this->a->define('bw', 'Bookworm', 10);
        $this->assertFalse($this->a->advance(42, 'bw', 7));  // 7/10
        $this->assertTrue($this->a->advance(42, 'bw', 3));   // hits 10 → unlocked
        $this->assertTrue($this->a->isUnlocked(42, 'bw'));
        $this->assertSame(10, $this->a->progress(42, 'bw'));
    }

    public function testAdvanceReturnsTrueOnlyOnUnlockingCall(): void
    {
        $this->a->define('bw', 'Bookworm', 5);
        $this->assertFalse($this->a->advance(42, 'bw', 4));
        $this->assertTrue($this->a->advance(42, 'bw', 1));  // crosses
        $this->assertFalse($this->a->advance(42, 'bw', 1)); // already unlocked
    }

    public function testProgressCapsAtTarget(): void
    {
        $this->a->define('bw', 'Bookworm', 5);
        $this->a->advance(42, 'bw', 100); // overshoot
        $this->assertSame(5, $this->a->progress(42, 'bw')); // capped
    }

    public function testProgressPct(): void
    {
        $this->a->define('bw', 'Bookworm', 4);
        $this->a->advance(42, 'bw', 1);
        $this->assertSame(0.25, $this->a->progressPct(42, 'bw'));
        $this->a->advance(42, 'bw', 3);
        $this->assertSame(1.0, $this->a->progressPct(42, 'bw'));
    }

    public function testUnlockedFor(): void
    {
        $this->a->define('a', 'A', 1);
        $this->a->define('b', 'B', 5);
        $this->a->advance(42, 'a', 1); // unlock a
        $this->a->advance(42, 'b', 2); // partial b
        $this->assertSame(['a'], $this->a->unlockedFor(42));
    }

    public function testProgressUnknownIsZero(): void
    {
        $this->a->define('bw', 'Bookworm', 5);
        $this->assertSame(0, $this->a->progress(42, 'bw'));
        $this->assertFalse($this->a->isUnlocked(42, 'bw'));
    }

    public function testUsersAreSeparate(): void
    {
        $this->a->define('bw', 'Bookworm', 2);
        $this->a->advance(1, 'bw', 2); // user 1 unlocks
        $this->assertTrue($this->a->isUnlocked(1, 'bw'));
        $this->assertFalse($this->a->isUnlocked(2, 'bw'));
        $this->assertSame(0, $this->a->progress(2, 'bw'));
    }

    public function testDefineIsIdempotent(): void
    {
        $this->a->define('bw', 'Bookworm', 10);
        $this->a->define('bw', 'Book Worm', 20);
        $this->a->advance(42, 'bw', 10);
        $this->assertFalse($this->a->isUnlocked(42, 'bw')); // target is now 20
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM achievement_defs')->fetchColumn());
    }

    public function testAdvanceUndefinedThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->a->advance(42, 'ghost', 1);
    }

    public function testAdvanceRejectsZeroIncrement(): void
    {
        $this->a->define('bw', 'Bookworm', 5);
        $this->expectException(\InvalidArgumentException::class);
        $this->a->advance(42, 'bw', 0);
    }

    public function testDefineRejectsZeroTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->a->define('bw', 'Bookworm', 0);
    }
}
