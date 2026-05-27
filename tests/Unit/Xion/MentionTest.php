<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\Mention;
use PDO;
use PHPUnit\Framework\TestCase;

final class MentionTest extends TestCase
{
    private PDO $pdo;
    private Mention $m;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE mentions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                author_id    VARCHAR(255) NOT NULL,
                mentioned_id VARCHAR(255) NOT NULL,
                read_at      DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, mentioned_id)
            )
        ');
        $this->m = new Mention($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordInsertsRows(): void
    {
        $n = $this->m->record('post', '1', 'author-1', ['user-2', 'user-3']);
        $this->assertSame(2, $n);
    }

    public function testRecordIsIdempotent(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $n = $this->m->record('post', '1', 'author-1', ['user-2']);
        $this->assertSame(0, $n);
    }

    public function testRecordReturnsZeroForEmptyList(): void
    {
        $this->assertSame(0, $this->m->record('post', '1', 'author-1', []));
    }

    public function testRecordSkipsBlankMentionedIds(): void
    {
        $n = $this->m->record('post', '1', 'author-1', ['user-2', '  ', 'user-3']);
        $this->assertSame(2, $n);
    }

    public function testRecordThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->record('', '1', 'author-1', ['user-2']);
    }

    public function testRecordThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->record('post', '', 'author-1', ['user-2']);
    }

    public function testRecordThrowsOnEmptyAuthorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->record('post', '1', '', ['user-2']);
    }

    // ── unreadFor ─────────────────────────────────────────────────────────────

    public function testUnreadForReturnsNewMentions(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $unread = $this->m->unreadFor('user-2');
        $this->assertCount(1, $unread);
        $this->assertNull($unread[0]['read_at']);
    }

    public function testUnreadForExcludesReadMentions(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $this->m->markAllRead('user-2');
        $this->assertSame([], $this->m->unreadFor('user-2'));
    }

    public function testUnreadForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->m->unreadFor('user-99'));
    }

    // ── allFor ────────────────────────────────────────────────────────────────

    public function testAllForReturnsBothReadAndUnread(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $this->m->record('post', '2', 'author-1', ['user-2']);
        $this->m->markAllRead('user-2');
        $this->m->record('post', '3', 'author-1', ['user-2']);

        $all = $this->m->allFor('user-2');
        $this->assertCount(3, $all);
    }

    // ── markRead ──────────────────────────────────────────────────────────────

    public function testMarkReadSetsReadAt(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $unread = $this->m->unreadFor('user-2');
        $ids    = array_column($unread, 'id');

        $n = $this->m->markRead('user-2', $ids);
        $this->assertSame(1, $n);
        $this->assertSame([], $this->m->unreadFor('user-2'));
    }

    public function testMarkReadOnlyAffectsGivenUser(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2', 'user-3']);
        $all    = $this->m->unreadFor('user-2');
        $ids    = array_column($all, 'id');

        $this->m->markRead('user-2', $ids);
        $this->assertSame(1, $this->m->unreadCount('user-3'));
    }

    public function testMarkReadReturnsZeroForEmptyIds(): void
    {
        $this->assertSame(0, $this->m->markRead('user-2', []));
    }

    // ── markAllRead ───────────────────────────────────────────────────────────

    public function testMarkAllReadClearsInbox(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $this->m->record('post', '2', 'author-1', ['user-2']);
        $n = $this->m->markAllRead('user-2');
        $this->assertSame(2, $n);
        $this->assertSame(0, $this->m->unreadCount('user-2'));
    }

    // ── unreadCount ───────────────────────────────────────────────────────────

    public function testUnreadCountIncreasesOnRecord(): void
    {
        $this->assertSame(0, $this->m->unreadCount('user-2'));
        $this->m->record('post', '1', 'author-1', ['user-2']);
        $this->assertSame(1, $this->m->unreadCount('user-2'));
        $this->m->record('post', '2', 'author-1', ['user-2']);
        $this->assertSame(2, $this->m->unreadCount('user-2'));
    }

    // ── mentionedIn ───────────────────────────────────────────────────────────

    public function testMentionedInListsUsers(): void
    {
        $this->m->record('comment', '5', 'author-1', ['user-2', 'user-3']);
        $who = $this->m->mentionedIn('comment', '5');
        sort($who);
        $this->assertSame(['user-2', 'user-3'], $who);
    }

    public function testMentionedInReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->m->mentionedIn('comment', '99'));
    }

    // ── deleteForEntity ───────────────────────────────────────────────────────

    public function testDeleteForEntityRemovesRows(): void
    {
        $this->m->record('post', '1', 'author-1', ['user-2', 'user-3']);
        $this->m->record('post', '2', 'author-1', ['user-2']);

        $n = $this->m->deleteForEntity('post', '1');
        $this->assertSame(2, $n);
        $this->assertSame([], $this->m->mentionedIn('post', '1'));
        $this->assertCount(1, $this->m->mentionedIn('post', '2'));
    }

    public function testDeleteForEntityReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->m->deleteForEntity('post', '99'));
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testUnreadForThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->unreadFor('');
    }

    public function testUnreadCountThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->unreadCount('');
    }

    public function testMarkAllReadThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->m->markAllRead('');
    }
}
