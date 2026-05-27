<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\VotePoll;
use PDO;
use PHPUnit\Framework\TestCase;

final class VotePollTest extends TestCase
{
    private PDO $pdo;
    private VotePoll $vp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE vote_polls (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                question   TEXT         NOT NULL,
                options    TEXT         NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'open\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE vote_poll_votes (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                poll_id  INTEGER      NOT NULL,
                user_id  VARCHAR(255) NOT NULL,
                option   VARCHAR(255) NOT NULL,
                voted_at DATETIME     NOT NULL,
                UNIQUE (poll_id, user_id)
            )
        ');
        $this->vp = new VotePoll($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresFields(): void
    {
        $id  = $this->vp->create('Colour?', ['Red', 'Blue', 'Green']);
        $row = $this->vp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Colour?', $row['question']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(VotePoll::STATUS_OPEN, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $opts = json_decode($row['options'], true);
        $this->assertSame(['Red', 'Blue', 'Green'], $opts);
    }

    public function testCreateThrowsOnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vp->create('', ['Red', 'Blue']);
    }

    public function testCreateThrowsOnSingleOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vp->create('Q?', ['Only']);
    }

    public function testCreateThrowsOnEmptyOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vp->create('Q?', []);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->vp->get(9999));
    }

    // ── vote ──────────────────────────────────────────────────────────────────

    public function testVoteRecordsChoice(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->assertSame('Red', $this->vp->userVote($id, 'user-1'));
    }

    public function testVoteChangesExistingChoice(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->vp->vote($id, 'user-1', 'Blue');
        $this->assertSame('Blue', $this->vp->userVote($id, 'user-1'));
    }

    public function testVoteIsIdempotentForSameOption(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->vp->vote($id, 'user-1', 'Red'); // no-op
        $this->assertSame(1, $this->vp->totalVotes($id));
    }

    public function testVoteThrowsOnEmptyUserId(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->expectException(\InvalidArgumentException::class);
        $this->vp->vote($id, '', 'Red');
    }

    public function testVoteThrowsOnInvalidOption(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->expectException(\InvalidArgumentException::class);
        $this->vp->vote($id, 'user-1', 'Yellow');
    }

    public function testVoteThrowsOnMissingPoll(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->vp->vote(9999, 'user-1', 'Red');
    }

    public function testVoteThrowsWhenPollClosed(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->close($id);
        $this->expectException(\RuntimeException::class);
        $this->vp->vote($id, 'user-1', 'Red');
    }

    // ── results ───────────────────────────────────────────────────────────────

    public function testResultsCountsVotes(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue', 'Green']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->vp->vote($id, 'user-2', 'Red');
        $this->vp->vote($id, 'user-3', 'Blue');

        $results = $this->vp->results($id);
        $this->assertCount(2, $results); // Green has 0 votes → not in results
        $this->assertSame('Red', $results[0]['option']);
        $this->assertSame(2, $results[0]['votes']);
        $this->assertSame('Blue', $results[1]['option']);
        $this->assertSame(1, $results[1]['votes']);
    }

    public function testResultsReturnsEmptyWhenNoVotes(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->assertSame([], $this->vp->results($id));
    }

    // ── userVote ──────────────────────────────────────────────────────────────

    public function testUserVoteReturnsNullWhenNotVoted(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->assertNull($this->vp->userVote($id, 'user-1'));
    }

    // ── totalVotes ────────────────────────────────────────────────────────────

    public function testTotalVotesCounts(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->vp->vote($id, 'user-2', 'Blue');
        $this->assertSame(2, $this->vp->totalVotes($id));
    }

    public function testTotalVotesIsZeroWhenNone(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->assertSame(0, $this->vp->totalVotes($id));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseChangesStatus(): void
    {
        $id  = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->assertTrue($this->vp->close($id));
        $row = $this->vp->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(VotePoll::STATUS_CLOSED, $row['status']);
    }

    public function testCloseReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->vp->close(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesPollAndVotes(): void
    {
        $id = $this->vp->create('Colour?', ['Red', 'Blue']);
        $this->vp->vote($id, 'user-1', 'Red');
        $this->assertTrue($this->vp->delete($id));
        $this->assertNull($this->vp->get($id));
        $this->assertSame(0, $this->vp->totalVotes($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->vp->delete(9999));
    }
}
