<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TeamMembership;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TeamMembership.
 */
final class TeamMembershipTest extends TestCase
{
    private PDO $db;
    private TeamMembership $tm;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE team_members (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                team_id     VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                role        VARCHAR(50)  NOT NULL DEFAULT \'member\',
                invited_by  VARCHAR(255) NOT NULL DEFAULT \'\',
                joined_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (team_id, user_id)
            )
        ');
        $this->tm = new TeamMembership($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddCreatesMembership(): void
    {
        $this->tm->add('team-a', 'user-1', 'owner');
        $this->assertTrue($this->tm->isMember('team-a', 'user-1'));
    }

    public function testAddDefaultRoleIsMember(): void
    {
        $this->tm->add('team-a', 'user-1');
        $this->assertSame('member', $this->tm->role('team-a', 'user-1'));
    }

    public function testAddIsIdempotentAndUpdatesRole(): void
    {
        $this->tm->add('team-a', 'user-1', 'member');
        $this->tm->add('team-a', 'user-1', 'admin');
        $this->assertSame('admin', $this->tm->role('team-a', 'user-1'));
        $this->assertSame(1, $this->tm->count('team-a'));
    }

    public function testAddThrowsOnEmptyTeamId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tm->add('', 'user-1');
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tm->add('team-a', '');
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesMembership(): void
    {
        $this->tm->add('team-a', 'user-1');
        $this->assertTrue($this->tm->remove('team-a', 'user-1'));
        $this->assertFalse($this->tm->isMember('team-a', 'user-1'));
    }

    public function testRemoveReturnsFalseForNonMember(): void
    {
        $this->assertFalse($this->tm->remove('team-a', 'nobody'));
    }

    // ── changeRole ────────────────────────────────────────────────────────────

    public function testChangeRoleUpdatesRole(): void
    {
        $this->tm->add('team-a', 'user-1', 'member');
        $this->assertTrue($this->tm->changeRole('team-a', 'user-1', 'admin'));
        $this->assertSame('admin', $this->tm->role('team-a', 'user-1'));
    }

    public function testChangeRoleReturnsFalseForNonMember(): void
    {
        $this->assertFalse($this->tm->changeRole('team-a', 'nobody', 'admin'));
    }

    // ── isMember / hasRole ───────────────────────────────────────────────────

    public function testIsMemberReturnsTrueForMember(): void
    {
        $this->tm->add('team-a', 'user-1');
        $this->assertTrue($this->tm->isMember('team-a', 'user-1'));
    }

    public function testIsMemberReturnsFalseForNonMember(): void
    {
        $this->assertFalse($this->tm->isMember('team-a', 'stranger'));
    }

    public function testHasRoleReturnsTrueForMatchingRole(): void
    {
        $this->tm->add('team-a', 'user-1', 'owner');
        $this->assertTrue($this->tm->hasRole('team-a', 'user-1', 'owner'));
    }

    public function testHasRoleReturnsFalseForWrongRole(): void
    {
        $this->tm->add('team-a', 'user-1', 'member');
        $this->assertFalse($this->tm->hasRole('team-a', 'user-1', 'owner'));
    }

    // ── role ──────────────────────────────────────────────────────────────────

    public function testRoleReturnsNullForNonMember(): void
    {
        $this->assertNull($this->tm->role('team-a', 'nobody'));
    }

    // ── members ───────────────────────────────────────────────────────────────

    public function testMembersListsAllMembersInOrder(): void
    {
        $this->tm->add('team-a', 'user-1', 'owner');
        $this->tm->add('team-a', 'user-2', 'member');
        $members = $this->tm->members('team-a');
        $this->assertCount(2, $members);
    }

    public function testMembersReturnsEmptyForUnknownTeam(): void
    {
        $this->assertSame([], $this->tm->members('no-team'));
    }

    // ── teams ─────────────────────────────────────────────────────────────────

    public function testTeamsListsAllTeamsForUser(): void
    {
        $this->tm->add('team-a', 'user-1');
        $this->tm->add('team-b', 'user-1');
        $teams = $this->tm->teams('user-1');
        $this->assertCount(2, $teams);
    }

    public function testTeamsReturnsEmptyForNonMember(): void
    {
        $this->assertSame([], $this->tm->teams('nobody'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectMemberCount(): void
    {
        $this->assertSame(0, $this->tm->count('team-a'));
        $this->tm->add('team-a', 'user-1');
        $this->tm->add('team-a', 'user-2');
        $this->assertSame(2, $this->tm->count('team-a'));
    }

    // ── disband ───────────────────────────────────────────────────────────────

    public function testDisbandRemovesAllMembers(): void
    {
        $this->tm->add('team-a', 'user-1');
        $this->tm->add('team-a', 'user-2');
        $count = $this->tm->disband('team-a');
        $this->assertSame(2, $count);
        $this->assertSame(0, $this->tm->count('team-a'));
    }

    public function testDisbandReturnsZeroForEmptyTeam(): void
    {
        $this->assertSame(0, $this->tm->disband('no-team'));
    }
}
