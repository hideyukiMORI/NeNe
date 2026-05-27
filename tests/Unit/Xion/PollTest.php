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
                question   TEXT        NOT NULL,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE poll_options (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                poll_id INTEGER     NOT NULL,
                label   VARCHAR(255) NOT NULL,
                sort    INTEGER     NOT NULL DEFAULT 0
            );
            CREATE TABLE poll_votes (
                poll_id   INTEGER      NOT NULL,
                option_id INTEGER      NOT NULL,
                user_id   VARCHAR(255) NOT NULL,
                voted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (poll_id, user_id)
            );
        ');
        $this->poll = new Poll($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsIntId(): void
    {
        $id = $this->poll->create('Favourite colour?', ['Red', 'Blue', 'Green']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateThrowsOnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->create('', ['A', 'B']);
    }

    public function testCreateThrowsWithFewerThanTwoOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->create('Q?', ['Only one']);
    }

    public function testCreateStoresQuestion(): void
    {
        $id  = $this->poll->create('Best PHP framework?', ['NeNe', 'Laravel']);
        $row = $this->poll->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Best PHP framework?', $row['question']);
    }

    // ── options ───────────────────────────────────────────────────────────────

    public function testOptionsReturnsAllOptionsInOrder(): void
    {
        $id      = $this->poll->create('Q?', ['Alpha', 'Beta', 'Gamma']);
        $options = $this->poll->options($id);
        $this->assertCount(3, $options);
        $this->assertSame('Alpha', $options[0]['label']);
        $this->assertSame('Beta', $options[1]['label']);
        $this->assertSame('Gamma', $options[2]['label']);
    }

    public function testOptionsReturnsEmptyForUnknownPoll(): void
    {
        $this->assertSame([], $this->poll->options(9999));
    }

    // ── vote ──────────────────────────────────────────────────────────────────

    public function testVoteReturnsTrueOnSuccess(): void
    {
        $pollId   = $this->poll->create('Q?', ['Yes', 'No']);
        $options  = $this->poll->options($pollId);
        $optionId = (int)$options[0]['id'];
        $this->assertTrue($this->poll->vote($pollId, $optionId, 'user-1'));
    }

    public function testVoteIsIdempotentForSameUser(): void
    {
        $pollId   = $this->poll->create('Q?', ['Yes', 'No']);
        $options  = $this->poll->options($pollId);
        $optionId = (int)$options[0]['id'];
        $this->poll->vote($pollId, $optionId, 'user-1');
        $this->assertFalse($this->poll->vote($pollId, $optionId, 'user-1')); // already voted
    }

    public function testVoteThrowsForWrongPoll(): void
    {
        $pollId  = $this->poll->create('Q?', ['A', 'B']);
        $pollId2 = $this->poll->create('R?', ['C', 'D']);
        $options2 = $this->poll->options($pollId2);
        $this->expectException(\InvalidArgumentException::class);
        $this->poll->vote($pollId, (int)$options2[0]['id'], 'user-1');
    }

    // ── results ───────────────────────────────────────────────────────────────

    public function testResultsReturnsZeroVotesInitially(): void
    {
        $id      = $this->poll->create('Q?', ['Yes', 'No']);
        $results = $this->poll->results($id);
        $this->assertCount(2, $results);
        $this->assertSame(0, $results[0]['votes']);
        $this->assertSame(0.0, $results[0]['percent']);
    }

    public function testResultsTallyCorrectly(): void
    {
        $pollId  = $this->poll->create('Q?', ['Yes', 'No']);
        $options = $this->poll->options($pollId);
        $yesId   = (int)$options[0]['id'];
        $noId    = (int)$options[1]['id'];

        $this->poll->vote($pollId, $yesId, 'user-1');
        $this->poll->vote($pollId, $yesId, 'user-2');
        $this->poll->vote($pollId, $noId, 'user-3');

        $results = $this->poll->results($pollId);
        $yes = $results[0];
        $no  = $results[1];

        $this->assertSame(2, $yes['votes']);
        $this->assertSame(1, $no['votes']);
        $this->assertEqualsWithDelta(66.7, $yes['percent'], 0.1);
        $this->assertEqualsWithDelta(33.3, $no['percent'], 0.1);
    }

    // ── hasVoted / userVote ───────────────────────────────────────────────────

    public function testHasVotedReturnsFalseBeforeVoting(): void
    {
        $id = $this->poll->create('Q?', ['A', 'B']);
        $this->assertFalse($this->poll->hasVoted($id, 'user-1'));
    }

    public function testHasVotedReturnsTrueAfterVoting(): void
    {
        $pollId  = $this->poll->create('Q?', ['A', 'B']);
        $options = $this->poll->options($pollId);
        $this->poll->vote($pollId, (int)$options[0]['id'], 'user-1');
        $this->assertTrue($this->poll->hasVoted($pollId, 'user-1'));
    }

    public function testUserVoteReturnsNullBeforeVoting(): void
    {
        $id = $this->poll->create('Q?', ['A', 'B']);
        $this->assertNull($this->poll->userVote($id, 'user-1'));
    }

    public function testUserVoteReturnsChosenOption(): void
    {
        $pollId  = $this->poll->create('Q?', ['A', 'B']);
        $options = $this->poll->options($pollId);
        $this->poll->vote($pollId, (int)$options[1]['id'], 'user-1');
        $vote = $this->poll->userVote($pollId, 'user-1');
        $this->assertNotNull($vote);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('B', $vote['label']);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingPoll(): void
    {
        $this->assertNull($this->poll->get(9999));
    }
}
