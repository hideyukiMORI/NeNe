<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PresenceChannel;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PresenceChannel.
 */
final class PresenceChannelTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE presence_channel (
                channel_name VARCHAR(255) NOT NULL,
                user_id      VARCHAR(255) NOT NULL,
                joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (channel_name, user_id)
            )
        ');
    }

    private function pc(int $ttl = 60): PresenceChannel
    {
        return new PresenceChannel($this->db, $ttl);
    }

    // ── constructor ───────────────────────────────────────────────────────────

    public function testConstructorThrowsOnNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PresenceChannel($this->db, 0);
    }

    // ── join ──────────────────────────────────────────────────────────────────

    public function testJoinAddsUserToChannel(): void
    {
        $this->pc()->join('room-1', 'user-1');
        $this->assertContains('user-1', $this->pc()->members('room-1'));
    }

    public function testJoinIsIdempotent(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $pc->join('room-1', 'user-1'); // no exception
        $this->assertSame(1, $pc->count('room-1'));
    }

    public function testJoinThrowsOnEmptyChannelName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pc()->join('', 'user-1');
    }

    public function testJoinThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pc()->join('room-1', '');
    }

    // ── heartbeat ─────────────────────────────────────────────────────────────

    public function testHeartbeatReturnsTrueForMember(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $this->assertTrue($pc->heartbeat('room-1', 'user-1'));
    }

    public function testHeartbeatReturnsFalseForNonMember(): void
    {
        $this->assertFalse($this->pc()->heartbeat('room-1', 'nobody'));
    }

    // ── leave ─────────────────────────────────────────────────────────────────

    public function testLeaveRemovesUserFromChannel(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $this->assertTrue($pc->leave('room-1', 'user-1'));
        $this->assertNotContains('user-1', $pc->members('room-1'));
    }

    public function testLeaveReturnsFalseIfNotMember(): void
    {
        $this->assertFalse($this->pc()->leave('room-1', 'nobody'));
    }

    // ── members ───────────────────────────────────────────────────────────────

    public function testMembersReturnsAllCurrentMembers(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $pc->join('room-1', 'user-2');
        $this->assertSame(['user-1', 'user-2'], $pc->members('room-1'));
    }

    public function testMembersExcludesStaleEntries(): void
    {
        $pc = $this->pc(ttl: 30);
        $pc->join('room-1', 'user-1');
        // Age the entry beyond TTL
        $this->db->exec(
            "UPDATE presence_channel SET last_seen = datetime('now', '-31 seconds')"
        );
        $this->assertSame([], $pc->members('room-1'));
    }

    public function testMembersReturnsEmptyForEmptyChannel(): void
    {
        $this->assertSame([], $this->pc()->members('empty'));
    }

    // ── isPresent ─────────────────────────────────────────────────────────────

    public function testIsPresentTrueForMember(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $this->assertTrue($pc->isPresent('room-1', 'user-1'));
    }

    public function testIsPresentFalseAfterLeave(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $pc->leave('room-1', 'user-1');
        $this->assertFalse($pc->isPresent('room-1', 'user-1'));
    }

    public function testIsPresentFalseForStaleEntry(): void
    {
        $pc = $this->pc(ttl: 30);
        $pc->join('room-1', 'user-1');
        $this->db->exec(
            "UPDATE presence_channel SET last_seen = datetime('now', '-31 seconds')"
        );
        $this->assertFalse($pc->isPresent('room-1', 'user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectCount(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $pc->join('room-1', 'user-2');
        $this->assertSame(2, $pc->count('room-1'));
    }

    public function testCountReturnsZeroForEmptyChannel(): void
    {
        $this->assertSame(0, $this->pc()->count('empty'));
    }

    // ── channelsForUser ───────────────────────────────────────────────────────

    public function testChannelsForUserReturnsChannels(): void
    {
        $pc = $this->pc();
        $pc->join('room-1', 'user-1');
        $pc->join('room-2', 'user-1');
        $channels = $pc->channelsForUser('user-1');
        $this->assertContains('room-1', $channels);
        $this->assertContains('room-2', $channels);
    }

    public function testChannelsForUserReturnsEmptyIfNone(): void
    {
        $this->assertSame([], $this->pc()->channelsForUser('nobody'));
    }

    // ── purgeStale ────────────────────────────────────────────────────────────

    public function testPurgeStaleDeletesExpiredEntries(): void
    {
        $pc = $this->pc(ttl: 30);
        $pc->join('room-1', 'user-1');
        $this->db->exec(
            "UPDATE presence_channel SET last_seen = datetime('now', '-31 seconds')"
        );
        $this->assertSame(1, $pc->purgeStale());
        $this->assertSame([], $pc->members('room-1'));
    }

    public function testPurgeStalePreservesActiveEntries(): void
    {
        $pc = $this->pc(ttl: 30);
        $pc->join('room-1', 'user-1');
        $this->assertSame(0, $pc->purgeStale());
        $this->assertContains('user-1', $pc->members('room-1'));
    }
}
