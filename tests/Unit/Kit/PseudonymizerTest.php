<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Pseudonymizer;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Pseudonymizer.
 */
final class PseudonymizerTest extends TestCase
{
    private PDO $db;
    private Pseudonymizer $pz;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE pseudonyms (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                namespace  VARCHAR(100) NOT NULL,
                real_value VARCHAR(255) NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (namespace, real_value),
                UNIQUE (namespace, token)
            )
        ');
        $this->pz = new Pseudonymizer($this->db);
    }

    public function testStableTokenForSameValue(): void
    {
        $a = $this->pz->pseudonymize('analytics', 'user-42');
        $b = $this->pz->pseudonymize('analytics', 'user-42');
        $this->assertSame($a, $b);
        $this->assertNotSame('', $a);
        $this->assertSame(1, $this->pz->count('analytics')); // not duplicated
    }

    public function testDifferentValuesGetDifferentTokens(): void
    {
        $a = $this->pz->pseudonymize('analytics', 'user-1');
        $b = $this->pz->pseudonymize('analytics', 'user-2');
        $this->assertNotSame($a, $b);
    }

    public function testReverse(): void
    {
        $t = $this->pz->pseudonymize('analytics', 'user-42');
        $this->assertSame('user-42', $this->pz->reverse('analytics', $t));
        $this->assertNull($this->pz->reverse('analytics', 'unknown-token'));
    }

    public function testNamespacesAreIndependent(): void
    {
        $a = $this->pz->pseudonymize('exportA', 'user-42');
        $b = $this->pz->pseudonymize('exportB', 'user-42');
        // same real value, different namespace → independent tokens
        $this->assertNotSame($a, $b);
        // reverse only resolves within the namespace
        $this->assertSame('user-42', $this->pz->reverse('exportA', $a));
        $this->assertNull($this->pz->reverse('exportB', $a));
    }

    public function testHas(): void
    {
        $this->pz->pseudonymize('ns', 'x');
        $this->assertTrue($this->pz->has('ns', 'x'));
        $this->assertFalse($this->pz->has('ns', 'y'));
    }

    public function testForget(): void
    {
        $t = $this->pz->pseudonymize('ns', 'x');
        $this->pz->forget('ns', 'x');
        $this->assertFalse($this->pz->has('ns', 'x'));
        $this->assertNull($this->pz->reverse('ns', $t));
    }

    public function testForgetThenRePseudonymizeGetsNewToken(): void
    {
        $first = $this->pz->pseudonymize('ns', 'x');
        $this->pz->forget('ns', 'x');
        $second = $this->pz->pseudonymize('ns', 'x');
        $this->assertNotSame($first, $second); // erasure means a fresh token
    }

    public function testCount(): void
    {
        $this->pz->pseudonymize('ns', 'a');
        $this->pz->pseudonymize('ns', 'b');
        $this->pz->pseudonymize('other', 'c');
        $this->assertSame(2, $this->pz->count('ns'));
        $this->assertSame(1, $this->pz->count('other'));
    }

    public function testForgetMissingIsNoop(): void
    {
        $this->pz->forget('ns', 'ghost');
        $this->assertSame(0, $this->pz->count('ns'));
    }

    public function testPseudonymizeRejectsEmptyNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pz->pseudonymize('  ', 'x');
    }

    public function testPseudonymizeRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pz->pseudonymize('ns', '  ');
    }
}
