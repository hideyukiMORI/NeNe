<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\FollowRelation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FollowRelation using an in-memory SQLite database.
 */
final class FollowRelationTest extends TestCase
{
    private PDO $db;
    private FollowRelation $fr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE follow_relations (
                follower_id VARCHAR(255) NOT NULL,
                followee_id VARCHAR(255) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (follower_id, followee_id)
            )'
        );
        $this->fr = new FollowRelation($this->db);
    }

    // ── follow ────────────────────────────────────────────────────────────────

    public function testFollowReturnsTrueOnSuccess(): void
    {
        $this->assertTrue($this->fr->follow('user:1', 'user:2'));
    }

    public function testFollowReturnsFalseIfAlreadyFollowing(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->assertFalse($this->fr->follow('user:1', 'user:2'));
    }

    public function testFollowReturnsFalseForSelfFollow(): void
    {
        $this->assertFalse($this->fr->follow('user:1', 'user:1'));
    }

    // ── unfollow ──────────────────────────────────────────────────────────────

    public function testUnfollowReturnsTrueWhenFollowing(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->assertTrue($this->fr->unfollow('user:1', 'user:2'));
    }

    public function testUnfollowReturnsFalseWhenNotFollowing(): void
    {
        $this->assertFalse($this->fr->unfollow('user:1', 'user:2'));
    }

    public function testUnfollowRemovesRelationship(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->fr->unfollow('user:1', 'user:2');
        $this->assertFalse($this->fr->isFollowing('user:1', 'user:2'));
    }

    // ── isFollowing ───────────────────────────────────────────────────────────

    public function testIsFollowingReturnsTrueAfterFollow(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->assertTrue($this->fr->isFollowing('user:1', 'user:2'));
    }

    public function testIsFollowingReturnsFalseBeforeFollow(): void
    {
        $this->assertFalse($this->fr->isFollowing('user:1', 'user:2'));
    }

    public function testIsFollowingIsDirectional(): void
    {
        $this->fr->follow('user:1', 'user:2');
        // user:2 has NOT followed user:1
        $this->assertFalse($this->fr->isFollowing('user:2', 'user:1'));
    }

    // ── followers ─────────────────────────────────────────────────────────────

    public function testFollowersReturnsUsersFollowingTarget(): void
    {
        $this->fr->follow('user:1', 'user:3');
        $this->fr->follow('user:2', 'user:3');
        $list = $this->fr->followers('user:3');
        $this->assertCount(2, $list);
    }

    public function testFollowersReturnsEmptyWhenNone(): void
    {
        $this->assertEmpty($this->fr->followers('user:3'));
    }

    public function testFollowersResultContainsFollowerIdAndCreatedAt(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $list = $this->fr->followers('user:2');
        $this->assertSame('user:1', $list[0]['follower_id']);
        $this->assertArrayHasKey('created_at', $list[0]);
    }

    // ── following ─────────────────────────────────────────────────────────────

    public function testFollowingReturnsUsersFollowedByTarget(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->fr->follow('user:1', 'user:3');
        $list = $this->fr->following('user:1');
        $this->assertCount(2, $list);
    }

    public function testFollowingReturnsEmptyWhenNone(): void
    {
        $this->assertEmpty($this->fr->following('user:1'));
    }

    public function testFollowingResultContainsFolloweeIdAndCreatedAt(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $list = $this->fr->following('user:1');
        $this->assertSame('user:2', $list[0]['followee_id']);
        $this->assertArrayHasKey('created_at', $list[0]);
    }

    // ── isMutual ──────────────────────────────────────────────────────────────

    public function testIsMutualReturnsTrueWhenBothFollow(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->fr->follow('user:2', 'user:1');
        $this->assertTrue($this->fr->isMutual('user:1', 'user:2'));
    }

    public function testIsMutualReturnsFalseWhenOnlyOneFollows(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->assertFalse($this->fr->isMutual('user:1', 'user:2'));
    }

    public function testIsMutualReturnsFalseWhenNeitherFollows(): void
    {
        $this->assertFalse($this->fr->isMutual('user:1', 'user:2'));
    }

    public function testIsMutualIsSymmetric(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->fr->follow('user:2', 'user:1');
        $this->assertSame(
            $this->fr->isMutual('user:1', 'user:2'),
            $this->fr->isMutual('user:2', 'user:1')
        );
    }

    // ── isolation ─────────────────────────────────────────────────────────────

    public function testFollowersOnlyReturnsFollowersOfSpecifiedUser(): void
    {
        $this->fr->follow('user:1', 'user:2');
        $this->fr->follow('user:1', 'user:3');
        // user:2's followers should not include user:3's followers
        $this->assertCount(1, $this->fr->followers('user:2'));
        $this->assertSame('user:1', $this->fr->followers('user:2')[0]['follower_id']);
    }
}
