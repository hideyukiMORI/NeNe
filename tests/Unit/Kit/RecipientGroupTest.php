<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\RecipientGroup;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecipientGroupTest extends TestCase
{
    private PDO $pdo;
    private RecipientGroup $rg;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE recipient_groups (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                slug        VARCHAR(100) NOT NULL UNIQUE,
                name        VARCHAR(255) NOT NULL,
                description TEXT         NULL,
                active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE recipient_group_members (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER      NOT NULL,
                user_id  VARCHAR(255) NOT NULL,
                email    VARCHAR(255) NULL,
                added_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (group_id, user_id)
            )
        ');
        $this->rg = new RecipientGroup($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresSlugAndName(): void
    {
        $id  = $this->rg->create('beta-testers', 'Beta Testers', 'Internal program');
        $row = $this->rg->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('beta-testers', $row['slug']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Beta Testers', $row['name']);
    }

    public function testCreateDefaultsNameToSlug(): void
    {
        $id  = $this->rg->create('beta-testers');
        $row = $this->rg->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('beta-testers', $row['name']);
    }

    public function testCreateThrowsOnEmptySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rg->create('');
    }

    public function testCreateThrowsOnDuplicateSlug(): void
    {
        $this->rg->create('beta-testers');
        $this->expectException(\RuntimeException::class);
        $this->rg->create('beta-testers');
    }

    // ── get / findBySlug ──────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->rg->get(9999));
    }

    public function testFindBySlugReturnsRow(): void
    {
        $id   = $this->rg->create('beta-testers');
        $row  = $this->rg->findBySlug('beta-testers');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
    }

    public function testFindBySlugReturnsNullForUnknown(): void
    {
        $this->assertNull($this->rg->findBySlug('nonexistent'));
    }

    // ── listActive ────────────────────────────────────────────────────────────

    public function testListActiveReturnsActiveGroups(): void
    {
        $this->rg->create('group-a');
        $this->rg->create('group-b');
        $active = $this->rg->listActive();
        $this->assertCount(2, $active);
    }

    // ── addMember ─────────────────────────────────────────────────────────────

    public function testAddMemberAddsUser(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1', 'alice@example.com');
        $this->assertTrue($this->rg->isMember($id, 'user-1'));
    }

    public function testAddMemberIsIdempotent(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1', 'alice@example.com');
        $this->rg->addMember($id, 'user-1', 'alice2@example.com');
        $this->assertSame(1, $this->rg->count($id));
    }

    public function testAddMemberThrowsOnEmptyUserId(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->expectException(\InvalidArgumentException::class);
        $this->rg->addMember($id, '');
    }

    // ── removeMember ──────────────────────────────────────────────────────────

    public function testRemoveMemberRemovesUser(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1');
        $this->assertTrue($this->rg->removeMember($id, 'user-1'));
        $this->assertFalse($this->rg->isMember($id, 'user-1'));
    }

    public function testRemoveMemberReturnsFalseWhenNotMember(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->assertFalse($this->rg->removeMember($id, 'user-nonexistent'));
    }

    // ── members / count ───────────────────────────────────────────────────────

    public function testMembersReturnsAll(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1');
        $this->rg->addMember($id, 'user-2');
        $this->assertCount(2, $this->rg->members($id));
    }

    public function testCountReturnsCorrectCount(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1');
        $this->rg->addMember($id, 'user-2');
        $this->assertSame(2, $this->rg->count($id));
    }

    // ── groupsFor ─────────────────────────────────────────────────────────────

    public function testGroupsForReturnsGroupsUserBelongsTo(): void
    {
        $g1 = $this->rg->create('group-a');
        $g2 = $this->rg->create('group-b');
        $this->rg->addMember($g1, 'user-1');
        $this->rg->addMember($g2, 'user-1');
        $this->rg->addMember($g1, 'user-2');
        $groups = $this->rg->groupsFor('user-1');
        $this->assertCount(2, $groups);
    }

    public function testGroupsForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->rg->groupsFor('user-nobody'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesGroupAndMembers(): void
    {
        $id = $this->rg->create('beta-testers');
        $this->rg->addMember($id, 'user-1');
        $this->assertTrue($this->rg->delete($id));
        $this->assertNull($this->rg->get($id));
        $this->assertSame(0, $this->rg->count($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rg->delete(9999));
    }
}
