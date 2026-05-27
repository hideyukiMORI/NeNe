<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SessionFlash;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SessionFlash.
 */
final class SessionFlashTest extends TestCase
{
    private PDO $db;
    private SessionFlash $sf;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE session_flash (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                token      VARCHAR(255) NOT NULL,
                category   VARCHAR(50)  NOT NULL DEFAULT \'info\',
                message    TEXT         NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sf = new SessionFlash($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->sf->add('tok-1', 'success', 'Saved.');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddThrowsOnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sf->add('', 'info', 'hello');
    }

    // ── consume ───────────────────────────────────────────────────────────────

    public function testConsumeReturnsMessages(): void
    {
        $this->sf->add('tok-1', 'success', 'Saved.');
        $this->sf->add('tok-1', 'info', 'Check email.');
        $msgs = $this->sf->consume('tok-1');
        $this->assertCount(2, $msgs);
    }

    public function testConsumeDeletesMessages(): void
    {
        $this->sf->add('tok-1', 'success', 'Saved.');
        $this->sf->consume('tok-1');
        $this->assertSame([], $this->sf->consume('tok-1'));
    }

    public function testConsumeReturnsInInsertOrder(): void
    {
        $this->sf->add('tok-1', 'info', 'first');
        $this->sf->add('tok-1', 'error', 'second');
        $msgs = $this->sf->consume('tok-1');
        $this->assertSame('first', $msgs[0]['message']);
        $this->assertSame('second', $msgs[1]['message']);
    }

    public function testConsumeReturnsEmptyForUnknownToken(): void
    {
        $this->assertSame([], $this->sf->consume('nobody'));
    }

    public function testConsumeIsTokenScoped(): void
    {
        $this->sf->add('tok-1', 'info', 'for tok-1');
        $this->sf->add('tok-2', 'info', 'for tok-2');
        $msgs = $this->sf->consume('tok-1');
        $this->assertCount(1, $msgs);
        $this->assertSame(1, $this->sf->count('tok-2'));
    }

    // ── consumeCategory ───────────────────────────────────────────────────────

    public function testConsumeCategoryReturnsOnlyMatchingCategory(): void
    {
        $this->sf->add('tok-1', 'success', 'ok');
        $this->sf->add('tok-1', 'error', 'bad');
        $msgs = $this->sf->consumeCategory('tok-1', 'error');
        $this->assertCount(1, $msgs);
        $this->assertSame('error', $msgs[0]['category']);
    }

    public function testConsumeCategoryDeletesOnlyMatchingCategory(): void
    {
        $this->sf->add('tok-1', 'success', 'ok');
        $this->sf->add('tok-1', 'error', 'bad');
        $this->sf->consumeCategory('tok-1', 'error');
        $this->assertSame(1, $this->sf->count('tok-1'));
    }

    // ── peek ──────────────────────────────────────────────────────────────────

    public function testPeekDoesNotDeleteMessages(): void
    {
        $this->sf->add('tok-1', 'info', 'hello');
        $this->sf->peek('tok-1');
        $this->assertSame(1, $this->sf->count('tok-1'));
    }

    public function testPeekReturnsMessages(): void
    {
        $this->sf->add('tok-1', 'info', 'hello');
        $msgs = $this->sf->peek('tok-1');
        $this->assertCount(1, $msgs);
        $this->assertSame('hello', $msgs[0]['message']);
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueWhenMessagesExist(): void
    {
        $this->sf->add('tok-1', 'info', 'hello');
        $this->assertTrue($this->sf->has('tok-1'));
    }

    public function testHasReturnsFalseWhenNoMessages(): void
    {
        $this->assertFalse($this->sf->has('tok-1'));
    }

    public function testHasByCategory(): void
    {
        $this->sf->add('tok-1', 'success', 'ok');
        $this->assertTrue($this->sf->has('tok-1', 'success'));
        $this->assertFalse($this->sf->has('tok-1', 'error'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsZeroInitially(): void
    {
        $this->assertSame(0, $this->sf->count('tok-1'));
    }

    public function testCountReturnsNumberOfMessages(): void
    {
        $this->sf->add('tok-1', 'info', 'a');
        $this->sf->add('tok-1', 'error', 'b');
        $this->assertSame(2, $this->sf->count('tok-1'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldMessages(): void
    {
        $id = $this->sf->add('tok-1', 'info', 'old');
        $this->db->exec("UPDATE session_flash SET created_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->sf->purgeOlderThan(1);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->sf->count('tok-1'));
    }

    public function testPurgeOlderThanLeavesRecentMessages(): void
    {
        $this->sf->add('tok-1', 'info', 'fresh');
        $this->assertSame(0, $this->sf->purgeOlderThan(30));
    }
}
