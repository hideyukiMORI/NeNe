<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\DailyDigest;
use PDO;
use PHPUnit\Framework\TestCase;

final class DailyDigestTest extends TestCase
{
    private PDO $pdo;
    private DailyDigest $dd;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE daily_digest_items (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                category   VARCHAR(100) NOT NULL DEFAULT \'\',
                content    TEXT         NOT NULL,
                sent       TINYINT(1)   NOT NULL DEFAULT 0,
                sent_at    DATETIME     NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dd = new DailyDigest($this->pdo);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->dd->add('user-1', 'comment', 'User X commented.');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dd->add('', 'cat', 'Content');
    }

    public function testAddThrowsOnEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dd->add('user-1', 'cat', '');
    }

    // ── pendingFor ────────────────────────────────────────────────────────────

    public function testPendingForReturnsUnsentItems(): void
    {
        $this->dd->add('user-1', 'comment', 'A');
        $this->dd->add('user-1', 'follow', 'B');
        $this->dd->add('user-2', 'comment', 'C');

        $items = $this->dd->pendingFor('user-1');
        $this->assertCount(2, $items);
        $this->assertSame(0, (int)$items[0]['sent']);
    }

    public function testPendingForExcludesSentItems(): void
    {
        $id = $this->dd->add('user-1', 'comment', 'A');
        $this->dd->markSent('user-1', [$id]);
        $this->assertSame([], $this->dd->pendingFor('user-1'));
    }

    public function testPendingForThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dd->pendingFor('');
    }

    // ── allPending ────────────────────────────────────────────────────────────

    public function testAllPendingGroupsByUser(): void
    {
        $this->dd->add('user-1', 'a', 'Item 1');
        $this->dd->add('user-2', 'b', 'Item 2');
        $this->dd->add('user-1', 'c', 'Item 3');

        $all = $this->dd->allPending();
        $this->assertArrayHasKey('user-1', $all);
        $this->assertArrayHasKey('user-2', $all);
        $this->assertCount(2, $all['user-1']);
        $this->assertCount(1, $all['user-2']);
    }

    public function testAllPendingExcludesSentItems(): void
    {
        $id = $this->dd->add('user-1', 'a', 'Sent');
        $this->dd->markSent('user-1', [$id]);
        $this->dd->add('user-1', 'b', 'Unsent');

        $all = $this->dd->allPending();
        $this->assertCount(1, $all['user-1']);
        $this->assertSame('Unsent', $all['user-1'][0]['content']);
    }

    // ── markSent ──────────────────────────────────────────────────────────────

    public function testMarkSentUpdatesSentFlag(): void
    {
        $id1 = $this->dd->add('user-1', 'a', 'A');
        $id2 = $this->dd->add('user-1', 'b', 'B');

        $n = $this->dd->markSent('user-1', [$id1, $id2]);
        $this->assertSame(2, $n);
        $this->assertSame(0, $this->dd->pendingCount('user-1'));
    }

    public function testMarkSentOnlyAffectsGivenUser(): void
    {
        $id1 = $this->dd->add('user-1', 'a', 'A');
        $this->dd->add('user-2', 'a', 'B');

        $this->dd->markSent('user-1', [$id1]);
        $this->assertSame(1, $this->dd->pendingCount('user-2'));
    }

    public function testMarkSentReturnsZeroForEmptyIds(): void
    {
        $this->assertSame(0, $this->dd->markSent('user-1', []));
    }

    // ── pendingCount ──────────────────────────────────────────────────────────

    public function testPendingCountIncreases(): void
    {
        $this->assertSame(0, $this->dd->pendingCount('user-1'));
        $this->dd->add('user-1', 'a', 'A');
        $this->assertSame(1, $this->dd->pendingCount('user-1'));
    }

    public function testPendingCountThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dd->pendingCount('');
    }

    // ── purgeSent ─────────────────────────────────────────────────────────────

    public function testPurgeSentDeletesOldSentRows(): void
    {
        $id = $this->dd->add('user-1', 'a', 'Old');
        $this->dd->markSent('user-1', [$id]);

        $n = $this->dd->purgeSent(0);
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame([], $this->dd->pendingFor('user-1'));
    }

    // ── deleteAll ─────────────────────────────────────────────────────────────

    public function testDeleteAllRemovesAllForUser(): void
    {
        $this->dd->add('user-1', 'a', 'A');
        $this->dd->add('user-1', 'b', 'B');
        $this->dd->add('user-2', 'c', 'C');

        $n = $this->dd->deleteAll('user-1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->dd->pendingFor('user-1'));
        $this->assertCount(1, $this->dd->pendingFor('user-2'));
    }

    public function testDeleteAllThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dd->deleteAll('');
    }
}
