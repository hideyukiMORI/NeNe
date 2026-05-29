<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\RoundRobinAssigner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoundRobinAssigner.
 */
final class RoundRobinAssignerTest extends TestCase
{
    private PDO $db;
    private RoundRobinAssigner $rr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE round_robin_pools (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                pool       VARCHAR(100) NOT NULL,
                members    TEXT         NOT NULL DEFAULT \'[]\',
                cursor     INTEGER      NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (pool)
            )
        ');
        $this->rr = new RoundRobinAssigner($this->db);
    }

    public function testNextRotatesAndWraps(): void
    {
        $this->rr->setMembers('support', ['alice', 'bob', 'carol']);
        $this->assertSame('alice', $this->rr->next('support'));
        $this->assertSame('bob', $this->rr->next('support'));
        $this->assertSame('carol', $this->rr->next('support'));
        $this->assertSame('alice', $this->rr->next('support')); // wraps
        $this->assertSame('bob', $this->rr->next('support'));
    }

    public function testNextEmptyPoolReturnsNull(): void
    {
        $this->rr->setMembers('empty', []);
        $this->assertNull($this->rr->next('empty'));
    }

    public function testNextUnknownPoolReturnsNull(): void
    {
        $this->assertNull($this->rr->next('ghost'));
    }

    public function testPeekDoesNotAdvance(): void
    {
        $this->rr->setMembers('p', ['a', 'b']);
        $this->assertSame('a', $this->rr->peek('p'));
        $this->assertSame('a', $this->rr->peek('p'));
        $this->assertSame('a', $this->rr->next('p'));
        $this->assertSame('b', $this->rr->peek('p'));
    }

    public function testSetMembersDedupesAndTrims(): void
    {
        $this->rr->setMembers('p', ['  a  ', 'b', 'a', '', 'b']);
        $this->assertSame(['a', 'b'], $this->rr->members('p'));
    }

    public function testSetMembersResetsCursor(): void
    {
        $this->rr->setMembers('p', ['a', 'b', 'c']);
        $this->rr->next('p'); // cursor → 1
        $this->rr->setMembers('p', ['x', 'y']); // reset
        $this->assertSame('x', $this->rr->next('p'));
    }

    public function testAddMemberAppends(): void
    {
        $this->rr->setMembers('p', ['a', 'b']);
        $this->rr->addMember('p', 'c');
        $this->assertSame(['a', 'b', 'c'], $this->rr->members('p'));
    }

    public function testAddMemberCreatesPool(): void
    {
        $this->rr->addMember('fresh', 'a');
        $this->assertSame(['a'], $this->rr->members('fresh'));
        $this->assertSame('a', $this->rr->next('fresh'));
    }

    public function testAddMemberIdempotent(): void
    {
        $this->rr->setMembers('p', ['a']);
        $this->rr->addMember('p', 'a');
        $this->assertSame(['a'], $this->rr->members('p'));
    }

    public function testRemoveMember(): void
    {
        $this->rr->setMembers('p', ['a', 'b', 'c']);
        $this->rr->removeMember('p', 'b');
        $this->assertSame(['a', 'c'], $this->rr->members('p'));
    }

    public function testRemoveMemberClampsCursor(): void
    {
        $this->rr->setMembers('p', ['a', 'b', 'c']);
        $this->rr->next('p'); // a, cursor → 1
        $this->rr->next('p'); // b, cursor → 2
        $this->rr->removeMember('p', 'c'); // now 2 members, cursor 2 % 2 = 0
        $this->assertSame('a', $this->rr->next('p'));
    }

    public function testReset(): void
    {
        $this->rr->setMembers('p', ['a', 'b']);
        $this->rr->next('p'); // a
        $this->rr->reset('p');
        $this->assertSame('a', $this->rr->next('p'));
    }

    public function testRemovePool(): void
    {
        $this->rr->setMembers('p', ['a']);
        $this->rr->remove('p');
        $this->assertSame([], $this->rr->members('p'));
        $this->assertNull($this->rr->next('p'));
    }

    public function testPoolsAreIndependent(): void
    {
        $this->rr->setMembers('p1', ['a', 'b']);
        $this->rr->setMembers('p2', ['x', 'y']);
        $this->assertSame('a', $this->rr->next('p1'));
        $this->assertSame('x', $this->rr->next('p2'));
        $this->assertSame('b', $this->rr->next('p1'));
    }

    public function testSetMembersRejectsEmptyPool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->setMembers('  ', ['a']);
    }

    public function testAddMemberRejectsEmptyMember(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->addMember('p', '   ');
    }
}
