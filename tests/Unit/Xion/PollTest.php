<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Poll;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Poll.
 */
final class PollTest extends TestCase
{
    private PDO $db;
    private Poll $poll;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE polls (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                question   TEXT     NOT NULL,
                options    TEXT     NOT NULL DEFAULT \'[]\',
                closed_at  DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE poll_votes (
                poll_id    INTEGER      NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                option_key VARCHAR(100) NOT NULL,
                voted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (poll_id, user_id, option_key)
            )
        ');
        $this->poll = new Poll($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->poll->create('Best colour?', ['red', 'blue']);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresOptions(): void
    {
        $id   = $this->poll->create('Pick one', ['a', 'b', 'c']);
        $poll = $this->poll->find($id);
        $this->assertSame(['a', 'b', 'c'], $poll['options']);
    }

    public function testCreateThrowsOnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->create('', ['a', 'b']);
    }

    public function testCreateThrowsOnEmptyOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->create('Question?', []);
    }

    // ── vote ──────────────────────────────────────────────────────────────────

    public function testVoteReturnsTrueOnSuccess(): void
    {
        $id = $this->poll->create('Q?', ['yes', 'no']);
        $this->assertTrue($this->poll->vote($id, 'user-1', 'yes'));
    }

    public function testVoteIsIdempotent(): void
    {
        $id = $this->poll->create('Q?', ['yes', 'no']);
        $this->poll->vote($id, 'user-1', 'yes');
        $this->assertFalse($this->poll->vote($id, 'user-1', 'yes'));
    }

    public function testVoteThrowsOnInvalidOption(): void
    {
        $id = $this->poll->create('Q?', ['yes', 'no']);
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->vote($id, 'user-1', 'maybe');
    }

    public function testVoteThrowsOnClosedPoll(): void
    {
        $id = $this->poll->create('Q?', ['yes', 'no']);
        $this->poll->close($id);
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->vote($id, 'user-1', 'yes');
    }

    public function testMultipleUsersCanVote(): void
    {
        $id = $this->poll->create('Q?', ['yes', 'no']);
        $this->poll->vote($id, 'user-1', 'yes');
        $this->poll->vote($id, 'user-2', 'yes');
        $this->poll->vote($id, 'user-3', 'no');
        $results = $this->poll->results($id);
        $this->assertSame(2, $results['yes']);
        $this->assertSame(1, $results['no']);
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseMarksClosedAt(): void
    {
        $id = $this->poll->create('Q?', ['a']);
        $this->assertTrue($this->poll->close($id));
        $poll = $this->poll->find($id);
        $this->assertNotNull($poll['closed_at']);
    }

    public function testCloseReturnsFalseIfAlreadyClosed(): void
    {
        $id = $this->poll->create('Q?', ['a']);
        $this->poll->close($id);
        $this->assertFalse($this->poll->close($id));
    }

    // ── results ───────────────────────────────────────────────────────────────

    public function testResultsInitialisesToZero(): void
    {
        $id      = $this->poll->create('Q?', ['a', 'b']);
        $results = $this->poll->results($id);
        $this->assertSame(['a' => 0, 'b' => 0], $results);
    }

    public function testResultsReflectsVotes(): void
    {
        $id = $this->poll->create('Q?', ['a', 'b']);
        $this->poll->vote($id, 'user-1', 'a');
        $this->poll->vote($id, 'user-2', 'a');
        $this->poll->vote($id, 'user-3', 'b');
        $results = $this->poll->results($id);
        $this->assertSame(2, $results['a']);
        $this->assertSame(1, $results['b']);
    }

    // ── hasVoted / votedFor ───────────────────────────────────────────────────

    public function testHasVotedReturnsFalseInitially(): void
    {
        $id = $this->poll->create('Q?', ['a', 'b']);
        $this->assertFalse($this->poll->hasVoted($id, 'user-1'));
    }

    public function testHasVotedReturnsTrueAfterVoting(): void
    {
        $id = $this->poll->create('Q?', ['a', 'b']);
        $this->poll->vote($id, 'user-1', 'a');
        $this->assertTrue($this->poll->hasVoted($id, 'user-1'));
    }

    public function testVotedForReturnsVotedOptions(): void
    {
        $id = $this->poll->create('Q?', ['a', 'b', 'c']);
        $this->poll->vote($id, 'user-1', 'a');
        $this->assertSame(['a'], $this->poll->votedFor($id, 'user-1'));
    }

    // ── totalVotes ────────────────────────────────────────────────────────────

    public function testTotalVotesReturnsCorrectCount(): void
    {
        $id = $this->poll->create('Q?', ['a', 'b']);
        $this->poll->vote($id, 'user-1', 'a');
        $this->poll->vote($id, 'user-2', 'b');
        $this->assertSame(2, $this->poll->totalVotes($id));
    }

    public function testTotalVotesReturnsZeroForNoPoll(): void
    {
        $this->assertSame(0, $this->poll->totalVotes(999));
    }
}
