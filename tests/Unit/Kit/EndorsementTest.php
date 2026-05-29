<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Endorsement;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Endorsement.
 */
final class EndorsementTest extends TestCase
{
    private PDO $db;
    private Endorsement $e;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE endorsements (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                subject_user BIGINT       NOT NULL,
                skill        VARCHAR(100) NOT NULL,
                endorser     BIGINT       NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (subject_user, skill, endorser)
            )
        ');
        $this->e = new Endorsement($this->db);
    }

    public function testEndorseAndCount(): void
    {
        $this->assertTrue($this->e->endorse(1, 'PHP', 2));
        $this->assertTrue($this->e->endorse(1, 'PHP', 3));
        $this->assertSame(2, $this->e->count(1, 'PHP'));
    }

    public function testEndorseIsIdempotent(): void
    {
        $this->assertTrue($this->e->endorse(1, 'PHP', 2));
        $this->assertFalse($this->e->endorse(1, 'PHP', 2)); // already endorsed
        $this->assertSame(1, $this->e->count(1, 'PHP'));
    }

    public function testHasEndorsed(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->assertTrue($this->e->hasEndorsed(1, 'PHP', 2));
        $this->assertFalse($this->e->hasEndorsed(1, 'PHP', 4));
    }

    public function testEndorsersListed(): void
    {
        $this->e->endorse(1, 'PHP', 5);
        $this->e->endorse(1, 'PHP', 2);
        $this->assertSame([2, 5], $this->e->endorsers(1, 'PHP'));
    }

    public function testTopSkillsOrderedByCount(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->e->endorse(1, 'PHP', 3);
        $this->e->endorse(1, 'SQL', 2);
        $top = $this->e->topSkills(1);
        $this->assertSame('PHP', $top[0]['skill']);
        $this->assertSame(2, $top[0]['count']);
        $this->assertSame('SQL', $top[1]['skill']);
    }

    public function testTopSkillsLimit(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->e->endorse(1, 'SQL', 2);
        $this->e->endorse(1, 'Go', 2);
        $this->assertCount(2, $this->e->topSkills(1, 2));
    }

    public function testWithdraw(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->e->withdraw(1, 'PHP', 2);
        $this->assertSame(0, $this->e->count(1, 'PHP'));
        $this->assertFalse($this->e->hasEndorsed(1, 'PHP', 2));
    }

    public function testWithdrawMissingIsNoop(): void
    {
        $this->e->withdraw(1, 'PHP', 2); // no throw
        $this->assertSame(0, $this->e->count(1, 'PHP'));
    }

    public function testWithdrawThenReEndorse(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->e->withdraw(1, 'PHP', 2);
        $this->assertTrue($this->e->endorse(1, 'PHP', 2)); // can re-add
    }

    public function testSubjectsAndSkillsAreScoped(): void
    {
        $this->e->endorse(1, 'PHP', 2);
        $this->assertSame(0, $this->e->count(99, 'PHP'));
        $this->assertSame(0, $this->e->count(1, 'SQL'));
    }

    public function testSelfEndorsementRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->e->endorse(1, 'PHP', 1);
    }

    public function testEndorseRejectsEmptySkill(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->e->endorse(1, '  ', 2);
    }
}
