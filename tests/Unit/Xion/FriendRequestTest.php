<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FriendRequest;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FriendRequest.
 */
final class FriendRequestTest extends TestCase
{
    private PDO $db;
    private FriendRequest $fr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE friend_requests (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_id VARCHAR(255) NOT NULL,
                requestee_id VARCHAR(255) NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (requester_id, requestee_id)
            )
        ');
        $this->fr = new FriendRequest($this->db);
    }

    // ── send ──────────────────────────────────────────────────────────────────

    public function testSendReturnsId(): void
    {
        $id = $this->fr->send('user-1', 'user-2');
        $this->assertGreaterThan(0, $id);
    }

    public function testSendCreatesPendingStatus(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->assertSame('pending', $this->fr->relationshipStatus('user-1', 'user-2'));
    }

    public function testSendThrowsOnSameUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fr->send('user-1', 'user-1');
    }

    public function testSendThrowsIfActiveRelationshipExists(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->expectException(\RuntimeException::class);
        $this->fr->send('user-1', 'user-2');
    }

    public function testSendThrowsIfReverseRelationshipIsPending(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->expectException(\RuntimeException::class);
        $this->fr->send('user-2', 'user-1'); // reverse also blocked
    }

    // ── accept ────────────────────────────────────────────────────────────────

    public function testAcceptSetsFriendsStatus(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->assertTrue($this->fr->accept('user-2', 'user-1'));
        $this->assertTrue($this->fr->areFriends('user-1', 'user-2'));
    }

    public function testAcceptReturnsFalseForNonPendingRequest(): void
    {
        $this->assertFalse($this->fr->accept('user-2', 'user-1'));
    }

    // ── decline ───────────────────────────────────────────────────────────────

    public function testDeclineSetsDeclinedStatus(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->assertTrue($this->fr->decline('user-2', 'user-1'));
        $this->assertSame('declined', $this->fr->relationshipStatus('user-1', 'user-2'));
    }

    public function testDeclineReturnsFalseIfNoRequest(): void
    {
        $this->assertFalse($this->fr->decline('user-2', 'user-1'));
    }

    // ── cancel / unfriend ─────────────────────────────────────────────────────

    public function testCancelRemovesPendingRequest(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->assertTrue($this->fr->cancel('user-1', 'user-2'));
        $this->assertSame('cancelled', $this->fr->relationshipStatus('user-1', 'user-2'));
    }

    public function testUnfriendRemovesAcceptedFriendship(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->fr->accept('user-2', 'user-1');
        $this->assertTrue($this->fr->unfriend('user-2', 'user-1'));
        $this->assertFalse($this->fr->areFriends('user-1', 'user-2'));
    }

    public function testCancelReturnsFalseIfNoRelationship(): void
    {
        $this->assertFalse($this->fr->cancel('user-1', 'user-2'));
    }

    // ── areFriends ────────────────────────────────────────────────────────────

    public function testAreFriendsFalseBeforeAccept(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->assertFalse($this->fr->areFriends('user-1', 'user-2'));
    }

    public function testAreFriendsReturnsTrueForBothDirections(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->fr->accept('user-2', 'user-1');
        $this->assertTrue($this->fr->areFriends('user-1', 'user-2'));
        $this->assertTrue($this->fr->areFriends('user-2', 'user-1'));
    }

    // ── pendingReceived / pendingSent ─────────────────────────────────────────

    public function testPendingReceivedListsIncomingRequests(): void
    {
        $this->fr->send('user-1', 'user-2');
        $received = $this->fr->pendingReceived('user-2');
        $this->assertCount(1, $received);
        $this->assertSame('user-1', $received[0]['requester_id']);
    }

    public function testPendingSentListsOutgoingRequests(): void
    {
        $this->fr->send('user-1', 'user-2');
        $sent = $this->fr->pendingSent('user-1');
        $this->assertCount(1, $sent);
        $this->assertSame('user-2', $sent[0]['requestee_id']);
    }

    // ── friends ───────────────────────────────────────────────────────────────

    public function testFriendsListReturnsFriendIds(): void
    {
        $this->fr->send('user-1', 'user-2');
        $this->fr->accept('user-2', 'user-1');
        $this->fr->send('user-3', 'user-1');
        $this->fr->accept('user-1', 'user-3');

        $friends = $this->fr->friends('user-1');
        sort($friends);
        $this->assertSame(['user-2', 'user-3'], $friends);
    }

    public function testFriendsListEmptyForUserWithNoFriends(): void
    {
        $this->assertSame([], $this->fr->friends('user-1'));
    }
}
