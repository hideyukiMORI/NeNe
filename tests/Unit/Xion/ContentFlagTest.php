<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ContentFlag;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentFlag.
 */
final class ContentFlagTest extends TestCase
{
    private PDO $db;
    private ContentFlag $cf;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE content_flags (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id  VARCHAR(255) NOT NULL,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                reason       VARCHAR(100) NOT NULL DEFAULT \'\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                moderator_id VARCHAR(255) DEFAULT NULL,
                note         TEXT         NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at  DATETIME     DEFAULT NULL,
                UNIQUE (reporter_id, entity_type, entity_id)
            )
        ');
        $this->cf = new ContentFlag($this->db);
    }

    // ── flag ──────────────────────────────────────────────────────────────────

    public function testFlagReturnsTrueOnFirstFlag(): void
    {
        $this->assertTrue($this->cf->flag('user-1', 'post', '42'));
    }

    public function testFlagIsIdempotentForSameReporter(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->assertFalse($this->cf->flag('user-1', 'post', '42'));
    }

    public function testMultipleReportersFlagSameEntity(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->cf->flag('user-2', 'post', '42');
        $queue = $this->cf->queue();
        $this->assertSame('2', (string)$queue[0]['flag_count']);
    }

    public function testFlagThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cf->flag('user-1', '', '42');
    }

    public function testFlagThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cf->flag('user-1', 'post', '');
    }

    public function testFlagStoresReason(): void
    {
        $this->cf->flag('user-1', 'post', '42', reason: 'spam');
        $flags = $this->cf->byReporter('user-1');
        $this->assertSame('spam', $flags[0]['reason']);
    }

    // ── moderate ──────────────────────────────────────────────────────────────

    public function testModerateApprovesFlags(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $count = $this->cf->moderate('post', '42', 'approve', 'mod-1');
        $this->assertSame(1, $count);
        $this->assertSame('approve', $this->cf->status('post', '42'));
    }

    public function testModerateRemovesFlags(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->cf->moderate('post', '42', 'remove', 'mod-1', 'Confirmed spam');
        $this->assertSame('remove', $this->cf->status('post', '42'));
    }

    public function testModerateUpdateAllPendingFlags(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->cf->flag('user-2', 'post', '42');
        $count = $this->cf->moderate('post', '42', 'dismiss', 'mod-1');
        $this->assertSame(2, $count);
    }

    public function testModerateThrowsOnInvalidAction(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->expectException(\InvalidArgumentException::class);
        $this->cf->moderate('post', '42', 'invalid', 'mod-1');
    }

    public function testModerateReturnZeroIfNoFlags(): void
    {
        $this->assertSame(0, $this->cf->moderate('post', '99', 'dismiss', 'mod-1'));
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function testStatusReturnsNullForNeverFlagged(): void
    {
        $this->assertNull($this->cf->status('post', '99'));
    }

    public function testStatusReturnsPendingForUnresolvedFlag(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->assertSame('pending', $this->cf->status('post', '42'));
    }

    // ── queue ─────────────────────────────────────────────────────────────────

    public function testQueueReturnsOnlyMatchingStatus(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->cf->flag('user-1', 'post', '99');
        $this->cf->moderate('post', '99', 'remove', 'mod-1');

        $pending = $this->cf->queue('pending');
        $this->assertCount(1, $pending);
        $this->assertSame('42', $pending[0]['entity_id']);
    }

    public function testQueueRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->cf->flag('user-1', 'post', (string)$i);
        }
        $this->assertCount(2, $this->cf->queue(limit: 2));
    }

    // ── byReporter ────────────────────────────────────────────────────────────

    public function testByReporterReturnsAllFlagsForUser(): void
    {
        $this->cf->flag('user-1', 'post', '42');
        $this->cf->flag('user-1', 'comment', '7');
        $flags = $this->cf->byReporter('user-1');
        $this->assertCount(2, $flags);
    }

    public function testByReporterReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->cf->byReporter('nobody'));
    }
}
