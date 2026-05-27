<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\UserGroup;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserGroup.
 */
final class UserGroupTest extends TestCase
{
    private PDO $db;
    private UserGroup $ug;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE user_groups (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        VARCHAR(100) NOT NULL UNIQUE,
                description TEXT         NOT NULL DEFAULT \'\',
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE user_group_members (
                group_id   INTEGER      NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                role       VARCHAR(50)  NOT NULL DEFAULT \'member\',
                joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (group_id, user_id)
            )
        ');
        $this->ug = new UserGroup($this->db);
    }

    // ── create / find / delete ────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->ug->create('engineers');
        $this->assertGreaterThan(0, $id);
    }

    public function testFindReturnsGroup(): void
    {
        $id  = $this->ug->create('engineers', 'Engineering team');
        $row = $this->ug->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('engineers', $row['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Engineering team', $row['description']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->ug->find(999));
    }

    public function testFindByName(): void
    {
        $this->ug->create('devops');
        $row = $this->ug->findByName('devops');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('devops', $row['name']);
    }

    public function testFindByNameReturnsNullForMissing(): void
    {
        $this->assertNull($this->ug->findByName('nope'));
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ug->create('');
    }

    public function testDeleteRemovesGroupAndMembers(): void
    {
        $id = $this->ug->create('temp');
        $this->ug->addMember($id, 'user-1');
        $this->assertTrue($this->ug->delete($id));
        $this->assertNull($this->ug->find($id));
        $this->assertFalse($this->ug->isMember($id, 'user-1'));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ug->delete(999));
    }

    // ── addMember / isMember ──────────────────────────────────────────────────

    public function testAddMemberDefaultRole(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1');
        $this->assertTrue($this->ug->isMember($id, 'user-1'));
        $this->assertSame('member', $this->ug->getRole($id, 'user-1'));
    }

    public function testAddMemberWithCustomRole(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1', 'admin');
        $this->assertSame('admin', $this->ug->getRole($id, 'user-1'));
    }

    public function testAddMemberIsIdempotentAndUpdatesRole(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1', 'member');
        $this->ug->addMember($id, 'user-1', 'admin');
        $this->assertSame('admin', $this->ug->getRole($id, 'user-1'));
        $this->assertSame(1, $this->ug->memberCount($id));
    }

    public function testIsMemberReturnsFalseForNonMember(): void
    {
        $id = $this->ug->create('g');
        $this->assertFalse($this->ug->isMember($id, 'user-99'));
    }

    // ── removeMember ─────────────────────────────────────────────────────────

    public function testRemoveMember(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1');
        $this->assertTrue($this->ug->removeMember($id, 'user-1'));
        $this->assertFalse($this->ug->isMember($id, 'user-1'));
    }

    public function testRemoveMemberReturnsFalseForNonMember(): void
    {
        $id = $this->ug->create('g');
        $this->assertFalse($this->ug->removeMember($id, 'user-99'));
    }

    // ── setRole ───────────────────────────────────────────────────────────────

    public function testSetRole(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1', 'member');
        $this->assertTrue($this->ug->setRole($id, 'user-1', 'admin'));
        $this->assertSame('admin', $this->ug->getRole($id, 'user-1'));
    }

    public function testSetRoleReturnsFalseForNonMember(): void
    {
        $id = $this->ug->create('g');
        $this->assertFalse($this->ug->setRole($id, 'user-99', 'admin'));
    }

    // ── listMembers / listGroups ──────────────────────────────────────────────

    public function testListMembers(): void
    {
        $id = $this->ug->create('g');
        $this->ug->addMember($id, 'user-1', 'admin');
        $this->ug->addMember($id, 'user-2');
        $members = $this->ug->listMembers($id);
        $this->assertCount(2, $members);
        $this->assertSame('user-1', $members[0]['user_id']);
        $this->assertSame('admin', $members[0]['role']);
    }

    public function testListGroups(): void
    {
        $id1 = $this->ug->create('g1');
        $id2 = $this->ug->create('g2');
        $this->ug->addMember($id1, 'user-1', 'admin');
        $this->ug->addMember($id2, 'user-1');
        $groups = $this->ug->listGroups('user-1');
        $this->assertCount(2, $groups);
        $this->assertSame('g1', $groups[0]['name']);
        $this->assertSame('admin', $groups[0]['role']);
    }

    // ── count / memberCount ───────────────────────────────────────────────────

    public function testCount(): void
    {
        $this->assertSame(0, $this->ug->count());
        $this->ug->create('a');
        $this->ug->create('b');
        $this->assertSame(2, $this->ug->count());
    }

    public function testMemberCount(): void
    {
        $id = $this->ug->create('g');
        $this->assertSame(0, $this->ug->memberCount($id));
        $this->ug->addMember($id, 'user-1');
        $this->ug->addMember($id, 'user-2');
        $this->assertSame(2, $this->ug->memberCount($id));
    }
}
