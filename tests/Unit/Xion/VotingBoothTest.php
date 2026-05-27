<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\VotingBooth;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VotingBooth using an in-memory SQLite database.
 */
final class VotingBoothTest extends TestCase
{
    private PDO $db;
    private VotingBooth $booth;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE votes (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                target_id  VARCHAR(255) NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                direction  VARCHAR(4)   NOT NULL CHECK (direction IN (\'up\', \'down\')),
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (target_id, user_id)
            )'
        );
        $this->booth = new VotingBooth($this->db);
    }

    // ── cast — new vote ───────────────────────────────────────────────────────

    public function testCastUpvoteReturnsUpVoteAndScore(): void
    {
        $result = $this->booth->cast('post:1', 'user:1', 'up');
        $this->assertSame('up', $result['vote']);
        $this->assertSame(1, $result['score']);
    }

    public function testCastDownvoteReturnsDownVoteAndNegativeScore(): void
    {
        $result = $this->booth->cast('post:1', 'user:1', 'down');
        $this->assertSame('down', $result['vote']);
        $this->assertSame(-1, $result['score']);
    }

    public function testCastInvalidDirectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->booth->cast('post:1', 'user:1', 'sideways');
    }

    // ── cast — toggle (same direction removes vote) ───────────────────────────

    public function testCastSameDirectionTogglesOff(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $result = $this->booth->cast('post:1', 'user:1', 'up'); // toggle
        $this->assertNull($result['vote']);
        $this->assertSame(0, $result['score']);
    }

    public function testCastToggleDownvote(): void
    {
        $this->booth->cast('post:1', 'user:1', 'down');
        $result = $this->booth->cast('post:1', 'user:1', 'down');
        $this->assertNull($result['vote']);
        $this->assertSame(0, $result['score']);
    }

    // ── cast — switch direction ────────────────────────────────────────────────

    public function testCastOppositeDirectionSwitches(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $result = $this->booth->cast('post:1', 'user:1', 'down'); // switch
        $this->assertSame('down', $result['vote']);
        $this->assertSame(-1, $result['score']);
    }

    public function testCastSwitchFromDownToUp(): void
    {
        $this->booth->cast('post:1', 'user:1', 'down');
        $result = $this->booth->cast('post:1', 'user:1', 'up');
        $this->assertSame('up', $result['vote']);
        $this->assertSame(1, $result['score']);
    }

    // ── score ─────────────────────────────────────────────────────────────────

    public function testScoreWithNoVotes(): void
    {
        $score = $this->booth->score('post:1');
        $this->assertSame(['up' => 0, 'down' => 0, 'score' => 0], $score);
    }

    public function testScoreCalculatesNetScore(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $this->booth->cast('post:1', 'user:2', 'up');
        $this->booth->cast('post:1', 'user:3', 'down');

        $score = $this->booth->score('post:1');
        $this->assertSame(2, $score['up']);
        $this->assertSame(1, $score['down']);
        $this->assertSame(1, $score['score']);
    }

    public function testScoreIsIsolatedPerTarget(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $this->booth->cast('post:2', 'user:1', 'down');

        $this->assertSame(1, $this->booth->score('post:1')['score']);
        $this->assertSame(-1, $this->booth->score('post:2')['score']);
    }

    // ── userVote ──────────────────────────────────────────────────────────────

    public function testUserVoteReturnsNullWhenNoVote(): void
    {
        $this->assertNull($this->booth->userVote('post:1', 'user:1'));
    }

    public function testUserVoteReturnsDirection(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $this->assertSame('up', $this->booth->userVote('post:1', 'user:1'));
    }

    public function testUserVoteReturnsNullAfterToggle(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $this->booth->cast('post:1', 'user:1', 'up'); // toggle off
        $this->assertNull($this->booth->userVote('post:1', 'user:1'));
    }

    public function testUserVoteIsIsolatedPerUser(): void
    {
        $this->booth->cast('post:1', 'user:1', 'up');
        $this->assertNull($this->booth->userVote('post:1', 'user:2'));
    }

    // ── validDirection ────────────────────────────────────────────────────────

    public function testValidDirectionAcceptsUpAndDown(): void
    {
        $this->assertTrue(VotingBooth::validDirection('up'));
        $this->assertTrue(VotingBooth::validDirection('down'));
    }

    public function testValidDirectionRejectsInvalid(): void
    {
        $this->assertFalse(VotingBooth::validDirection(''));
        $this->assertFalse(VotingBooth::validDirection('left'));
        $this->assertFalse(VotingBooth::validDirection('UP'));
    }
}
