<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\UserSegment;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserSegmentTest extends TestCase
{
    private PDO $pdo;
    private UserSegment $seg;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_segments (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        VARCHAR(100) NOT NULL UNIQUE,
                description TEXT         NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE user_segment_members (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                segment  VARCHAR(100) NOT NULL,
                user_id  VARCHAR(255) NOT NULL,
                added_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (segment, user_id)
            )
        ');
        $this->seg = new UserSegment($this->pdo);
    }

    // ── createSegment ─────────────────────────────────────────────────────────

    public function testCreateSegmentAddsToList(): void
    {
        $this->seg->createSegment('beta-testers', 'Beta program');
        $segments = $this->seg->allSegments();
        $this->assertCount(1, $segments);
        $this->assertSame('beta-testers', $segments[0]['name']);
        $this->assertSame('Beta program', $segments[0]['description']);
    }

    public function testCreateSegmentIsIdempotent(): void
    {
        $this->seg->createSegment('beta');
        $this->seg->createSegment('beta');
        $this->assertCount(1, $this->seg->allSegments());
    }

    public function testCreateSegmentThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seg->createSegment('');
    }

    // ── deleteSegment ─────────────────────────────────────────────────────────

    public function testDeleteSegmentRemovesSegmentAndMembers(): void
    {
        $this->seg->createSegment('old');
        $this->seg->addUser('old', 'u1');
        $this->assertTrue($this->seg->deleteSegment('old'));
        $this->assertCount(0, $this->seg->allSegments());
        $this->assertSame([], $this->seg->usersIn('old'));
    }

    public function testDeleteSegmentReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->seg->deleteSegment('nonexistent'));
    }

    // ── allSegments ───────────────────────────────────────────────────────────

    public function testAllSegmentsReturnsAlphabeticList(): void
    {
        $this->seg->createSegment('z-seg');
        $this->seg->createSegment('a-seg');
        $segments = $this->seg->allSegments();
        $this->assertSame('a-seg', $segments[0]['name']);
        $this->assertSame('z-seg', $segments[1]['name']);
    }

    // ── addUser / isMember ────────────────────────────────────────────────────

    public function testAddUserMakesUserMember(): void
    {
        $this->seg->addUser('beta', 'u1');
        $this->assertTrue($this->seg->isMember('beta', 'u1'));
    }

    public function testAddUserIsIdempotent(): void
    {
        $this->seg->addUser('beta', 'u1');
        $this->seg->addUser('beta', 'u1');
        $this->assertSame(1, $this->seg->memberCount('beta'));
    }

    public function testIsMemberReturnsFalseWhenNot(): void
    {
        $this->assertFalse($this->seg->isMember('beta', 'u99'));
    }

    public function testAddUserThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seg->addUser('seg', '');
    }

    // ── removeUser ────────────────────────────────────────────────────────────

    public function testRemoveUserRemovesMembership(): void
    {
        $this->seg->addUser('beta', 'u1');
        $this->assertTrue($this->seg->removeUser('beta', 'u1'));
        $this->assertFalse($this->seg->isMember('beta', 'u1'));
    }

    public function testRemoveUserReturnsFalseWhenNotMember(): void
    {
        $this->assertFalse($this->seg->removeUser('beta', 'u99'));
    }

    // ── usersIn / memberCount ─────────────────────────────────────────────────

    public function testUsersInReturnsAlphabeticList(): void
    {
        $this->seg->addUser('seg', 'u3');
        $this->seg->addUser('seg', 'u1');
        $this->seg->addUser('seg', 'u2');

        $users = $this->seg->usersIn('seg');
        $this->assertSame(['u1', 'u2', 'u3'], $users);
    }

    public function testMemberCountIsCorrect(): void
    {
        $this->seg->addUser('seg', 'u1');
        $this->seg->addUser('seg', 'u2');
        $this->assertSame(2, $this->seg->memberCount('seg'));
    }

    // ── segmentsFor ───────────────────────────────────────────────────────────

    public function testSegmentsForReturnsUsersSegments(): void
    {
        $this->seg->addUser('b-seg', 'u1');
        $this->seg->addUser('a-seg', 'u1');
        $this->seg->addUser('b-seg', 'u2');

        $segs = $this->seg->segmentsFor('u1');
        $this->assertSame(['a-seg', 'b-seg'], $segs);
    }

    public function testSegmentsForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->seg->segmentsFor('u99'));
    }

    // ── removeFromAll ─────────────────────────────────────────────────────────

    public function testRemoveFromAllRemovesAllMemberships(): void
    {
        $this->seg->addUser('seg1', 'u1');
        $this->seg->addUser('seg2', 'u1');
        $this->seg->addUser('seg1', 'u2');

        $count = $this->seg->removeFromAll('u1');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->seg->segmentsFor('u1'));
        $this->assertCount(1, $this->seg->usersIn('seg1')); // u2 still in
    }
}
