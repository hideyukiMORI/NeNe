<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Petition;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Petition.
 */
final class PetitionTest extends TestCase
{
    private PDO $db;
    private Petition $p;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE petitions (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(150) NOT NULL,
                goal       INTEGER      NOT NULL,
                closed     INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (name)
            )
        ');
        $this->db->exec('
            CREATE TABLE petition_signatures (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                petition  VARCHAR(150) NOT NULL,
                signer    VARCHAR(190) NOT NULL,
                comment   TEXT         NOT NULL DEFAULT \'\',
                signed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (petition, signer)
            )
        ');
        $this->p = new Petition($this->db);
    }

    public function testCreateSignAndCount(): void
    {
        $this->p->create('park', 1000);
        $this->assertTrue($this->p->sign('park', 'alice', 'keep it green'));
        $this->assertTrue($this->p->sign('park', 'bob'));
        $this->assertSame(2, $this->p->signatureCount('park'));
    }

    public function testSignIsIdempotentPerSigner(): void
    {
        $this->p->create('park', 1000);
        $this->assertTrue($this->p->sign('park', 'alice'));
        $this->assertFalse($this->p->sign('park', 'alice')); // already signed
        $this->assertSame(1, $this->p->signatureCount('park'));
    }

    public function testHasSigned(): void
    {
        $this->p->create('park', 1000);
        $this->p->sign('park', 'alice');
        $this->assertTrue($this->p->hasSigned('park', 'alice'));
        $this->assertFalse($this->p->hasSigned('park', 'bob'));
    }

    public function testGoalReached(): void
    {
        $this->p->create('park', 2);
        $this->p->sign('park', 'a');
        $this->assertFalse($this->p->goalReached('park'));
        $this->p->sign('park', 'b');
        $this->assertTrue($this->p->goalReached('park')); // count == goal
    }

    public function testProgress(): void
    {
        $this->p->create('park', 4);
        $this->p->sign('park', 'a');
        $prog = $this->p->progress('park');
        $this->assertSame(1, $prog['count']);
        $this->assertSame(4, $prog['goal']);
        $this->assertFalse($prog['reached']);
        $this->assertSame(0.25, $prog['pct']);
    }

    public function testProgressPctCapsAtOne(): void
    {
        $this->p->create('park', 1);
        $this->p->sign('park', 'a');
        $this->p->sign('park', 'b'); // exceeds goal
        $this->assertSame(1.0, $this->p->progress('park')['pct']);
        $this->assertTrue($this->p->progress('park')['reached']);
    }

    public function testProgressUnknownIsNull(): void
    {
        $this->assertNull($this->p->progress('nope'));
        $this->assertFalse($this->p->goalReached('nope'));
    }

    public function testCloseStopsSigning(): void
    {
        $this->p->create('park', 1000);
        $this->p->sign('park', 'a');
        $this->p->close('park');
        $this->assertTrue($this->p->isClosed('park'));
        $this->expectException(\InvalidArgumentException::class);
        $this->p->sign('park', 'b');
    }

    public function testRecreateReopens(): void
    {
        $this->p->create('park', 1000);
        $this->p->close('park');
        $this->p->create('park', 500); // re-create reopens + new goal
        $this->assertFalse($this->p->isClosed('park'));
        $this->assertTrue($this->p->sign('park', 'a'));
        $this->assertSame(500, $this->p->progress('park')['goal']);
    }

    public function testSignatures(): void
    {
        $this->p->create('park', 1000);
        $this->p->sign('park', 'a', 'first');
        $this->p->sign('park', 'b', 'second');
        $sigs = $this->p->signatures('park');
        $this->assertSame('b', $sigs[0]['signer']); // newest first
        $this->assertSame('second', $sigs[0]['comment']);
    }

    public function testPetitionsAreSeparate(): void
    {
        $this->p->create('a', 10);
        $this->p->create('b', 10);
        $this->p->sign('a', 'x');
        $this->assertSame(1, $this->p->signatureCount('a'));
        $this->assertSame(0, $this->p->signatureCount('b'));
    }

    public function testSignUnknownPetitionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->sign('ghost', 'a');
    }

    public function testCreateRejectsZeroGoal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->p->create('park', 0);
    }
}
