<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DraftManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DraftManager.
 */
final class DraftManagerTest extends TestCase
{
    private PDO $db;
    private DraftManager $dm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE drafts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                draft_type VARCHAR(100) NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                version    INTEGER      NOT NULL DEFAULT 1,
                title      VARCHAR(500) NOT NULL DEFAULT \'\',
                content    TEXT         NOT NULL DEFAULT \'{}\',
                saved_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dm = new DraftManager($this->db);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveReturnsVersion1OnFirstSave(): void
    {
        $v = $this->dm->save('post', 'user-1', 'Draft 1', ['body' => 'Hello']);
        $this->assertSame(1, $v);
    }

    public function testSaveIncrementsVersion(): void
    {
        $this->dm->save('post', 'user-1', 'v1');
        $v2 = $this->dm->save('post', 'user-1', 'v2');
        $this->assertSame(2, $v2);
    }

    public function testSaveStoresContent(): void
    {
        $this->dm->save('post', 'user-1', 'Title', ['body' => 'World']);
        $draft = $this->dm->latest('post', 'user-1');
        $this->assertNotNull($draft);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(['body' => 'World'], $draft['content']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Title', $draft['title']);
    }

    public function testSaveThrowsOnEmptyDraftType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dm->save('', 'user-1');
    }

    public function testSaveThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dm->save('post', '');
    }

    // ── latest ────────────────────────────────────────────────────────────────

    public function testLatestReturnsNewestVersion(): void
    {
        $this->dm->save('post', 'user-1', 'v1');
        $this->dm->save('post', 'user-1', 'v2');
        $draft = $this->dm->latest('post', 'user-1');
        $this->assertNotNull($draft);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$draft['version']);
    }

    public function testLatestReturnsNullWhenNoDrafts(): void
    {
        $this->assertNull($this->dm->latest('post', 'user-1'));
    }

    public function testLatestIsUserScoped(): void
    {
        $this->dm->save('post', 'user-1', 'v1');
        $this->assertNull($this->dm->latest('post', 'user-2'));
    }

    // ── version ───────────────────────────────────────────────────────────────

    public function testVersionReturnsSpecificVersion(): void
    {
        $this->dm->save('post', 'user-1', 'v1-title');
        $this->dm->save('post', 'user-1', 'v2-title');
        $row = $this->dm->version('post', 'user-1', 1);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('v1-title', $row['title']);
    }

    public function testVersionReturnsNullForNonExistentVersion(): void
    {
        $this->assertNull($this->dm->version('post', 'user-1', 99));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsAllVersionsNewestFirst(): void
    {
        $this->dm->save('post', 'user-1', 'v1');
        $this->dm->save('post', 'user-1', 'v2');
        $this->dm->save('post', 'user-1', 'v3');
        $history = $this->dm->history('post', 'user-1');
        $this->assertCount(3, $history);
        $this->assertSame(3, (int)$history[0]['version']);
        $this->assertSame(1, (int)$history[2]['version']);
    }

    public function testHistoryReturnsEmptyWhenNoDrafts(): void
    {
        $this->assertSame([], $this->dm->history('post', 'user-1'));
    }

    // ── hasDraft ──────────────────────────────────────────────────────────────

    public function testHasDraftTrueAfterSave(): void
    {
        $this->dm->save('post', 'user-1');
        $this->assertTrue($this->dm->hasDraft('post', 'user-1'));
    }

    public function testHasDraftFalseBeforeSave(): void
    {
        $this->assertFalse($this->dm->hasDraft('post', 'user-1'));
    }

    // ── versionCount ──────────────────────────────────────────────────────────

    public function testVersionCountReturnsCorrectCount(): void
    {
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->assertSame(2, $this->dm->versionCount('post', 'user-1'));
    }

    // ── discard ───────────────────────────────────────────────────────────────

    public function testDiscardRemovesAllVersions(): void
    {
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->assertSame(2, $this->dm->discard('post', 'user-1'));
        $this->assertFalse($this->dm->hasDraft('post', 'user-1'));
    }

    public function testDiscardDoesNotAffectOtherUsers(): void
    {
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-2');
        $this->dm->discard('post', 'user-1');
        $this->assertTrue($this->dm->hasDraft('post', 'user-2'));
    }

    // ── pruneHistory ──────────────────────────────────────────────────────────

    public function testPruneHistoryKeepsNewestVersions(): void
    {
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $deleted = $this->dm->pruneHistory('post', 'user-1', 3);
        $this->assertSame(2, $deleted);
        $this->assertSame(3, $this->dm->versionCount('post', 'user-1'));
        // Newest 3 remain: versions 3, 4, 5
        $latest = $this->dm->latest('post', 'user-1');
        $this->assertNotNull($latest);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(5, (int)$latest['version']);
    }

    public function testPruneHistoryReturnsZeroWhenNotEnoughVersions(): void
    {
        $this->dm->save('post', 'user-1');
        $this->dm->save('post', 'user-1');
        $this->assertSame(0, $this->dm->pruneHistory('post', 'user-1', 5));
    }
}
