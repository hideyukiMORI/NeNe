<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\FeatureRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class FeatureRequestTest extends TestCase
{
    private PDO $pdo;
    private FeatureRequest $fr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE feature_requests (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                submitter_id VARCHAR(255) NOT NULL,
                title        TEXT         NOT NULL,
                description  TEXT         NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'open\',
                vote_count   INTEGER      NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE feature_request_votes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id INTEGER      NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                voted_at   DATETIME     NOT NULL,
                UNIQUE (request_id, user_id)
            )
        ');
        $this->fr = new FeatureRequest($this->pdo);
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function testSubmitReturnsId(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertGreaterThan(0, $id);
    }

    public function testSubmitStoresFields(): void
    {
        $id  = $this->fr->submit('user-1', 'Dark mode', 'Please add a dark theme');
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['submitter_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Dark mode', $row['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Please add a dark theme', $row['description']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_OPEN, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['vote_count']);
    }

    public function testSubmitThrowsOnEmptySubmitterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fr->submit('', 'Dark mode');
    }

    public function testSubmitThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fr->submit('user-1', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->fr->get(9999));
    }

    // ── upvote ────────────────────────────────────────────────────────────────

    public function testUpvoteIncreasesVoteCount(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertTrue($this->fr->upvote($id, 'user-2'));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['vote_count']);
    }

    public function testUpvoteReturnsFalseIfAlreadyVoted(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->assertFalse($this->fr->upvote($id, 'user-2'));
    }

    public function testUpvoteReturnsFalseForMissingRequest(): void
    {
        $this->assertFalse($this->fr->upvote(9999, 'user-2'));
    }

    public function testUpvoteThrowsOnEmptyUserId(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->expectException(\InvalidArgumentException::class);
        $this->fr->upvote($id, '');
    }

    public function testMultipleUsersCanVote(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->fr->upvote($id, 'user-3');
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['vote_count']);
    }

    // ── downvote ──────────────────────────────────────────────────────────────

    public function testDownvoteDecreasesVoteCount(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->assertTrue($this->fr->downvote($id, 'user-2'));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['vote_count']);
    }

    public function testDownvoteReturnsFalseIfNotVoted(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertFalse($this->fr->downvote($id, 'user-2'));
    }

    // ── hasVoted ──────────────────────────────────────────────────────────────

    public function testHasVotedReturnsTrueAfterUpvote(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->assertTrue($this->fr->hasVoted($id, 'user-2'));
    }

    public function testHasVotedReturnsFalseWhenNotVoted(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertFalse($this->fr->hasVoted($id, 'user-2'));
    }

    public function testHasVotedReturnsFalseAfterDownvote(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->fr->downvote($id, 'user-2');
        $this->assertFalse($this->fr->hasVoted($id, 'user-2'));
    }

    // ── plan / startWork / ship ───────────────────────────────────────────────

    public function testPlanChangesOpenToPlanned(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertTrue($this->fr->plan($id));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_PLANNED, $row['status']);
    }

    public function testStartWorkChangesPlanedToInProgress(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->plan($id);
        $this->assertTrue($this->fr->startWork($id));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_IN_PROGRESS, $row['status']);
    }

    public function testShipChangesStatus(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->plan($id);
        $this->fr->startWork($id);
        $this->assertTrue($this->fr->ship($id));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_SHIPPED, $row['status']);
    }

    public function testShipCanSkipStartWork(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->plan($id);
        $this->assertTrue($this->fr->ship($id));
    }

    public function testPlanReturnsFalseWhenNotOpen(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->plan($id);
        $this->assertFalse($this->fr->plan($id)); // already planned
    }

    // ── decline / markDuplicate ───────────────────────────────────────────────

    public function testDeclineChangesStatus(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertTrue($this->fr->decline($id));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_DECLINED, $row['status']);
    }

    public function testMarkDuplicateChangesStatus(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->assertTrue($this->fr->markDuplicate($id));
        $row = $this->fr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(FeatureRequest::STATUS_DUPLICATE, $row['status']);
    }

    // ── top ───────────────────────────────────────────────────────────────────

    public function testTopOrdersByVoteCount(): void
    {
        $id1 = $this->fr->submit('user-1', 'Dark mode');
        $id2 = $this->fr->submit('user-1', 'Light mode');
        $this->fr->upvote($id1, 'user-2');
        $this->fr->upvote($id1, 'user-3');
        $this->fr->upvote($id2, 'user-4');
        $top = $this->fr->top();
        $this->assertSame($id1, (int)$top[0]['id']);
    }

    public function testTopFiltersByStatus(): void
    {
        $id1 = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->submit('user-1', 'Light mode');
        $this->fr->plan($id1);
        $this->assertCount(1, $this->fr->top(20, FeatureRequest::STATUS_PLANNED));
        $this->assertCount(1, $this->fr->top(20, FeatureRequest::STATUS_OPEN));
    }

    // ── bySubmitter ───────────────────────────────────────────────────────────

    public function testBySubmitterFilters(): void
    {
        $this->fr->submit('user-1', 'Dark mode');
        $this->fr->submit('user-1', 'Light mode');
        $this->fr->submit('user-2', 'Bold font');
        $this->assertCount(2, $this->fr->bySubmitter('user-1'));
        $this->assertCount(1, $this->fr->bySubmitter('user-2'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRequestAndVotes(): void
    {
        $id = $this->fr->submit('user-1', 'Dark mode');
        $this->fr->upvote($id, 'user-2');
        $this->assertTrue($this->fr->delete($id));
        $this->assertNull($this->fr->get($id));
    }

    public function testDeleteReturnsFalseForMissingRequest(): void
    {
        $this->assertFalse($this->fr->delete(9999));
    }
}
