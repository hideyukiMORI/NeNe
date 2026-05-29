<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ChecksumRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChecksumRegistry.
 */
final class ChecksumRegistryTest extends TestCase
{
    private PDO $db;
    private ChecksumRegistry $cr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE checksums (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ref        VARCHAR(190) NOT NULL,
                algo       VARCHAR(20)  NOT NULL DEFAULT \'sha256\',
                checksum   VARCHAR(128) NOT NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (ref)
            )
        ');
        $this->cr = new ChecksumRegistry($this->db);
    }

    public function testPutReturnsSha256(): void
    {
        $hash = $this->cr->put('f', 'hello');
        $this->assertSame(hash('sha256', 'hello'), $hash);
    }

    public function testVerifyMatchesAndDetectsTamper(): void
    {
        $this->cr->put('config', 'original');
        $this->assertTrue($this->cr->verify('config', 'original'));
        $this->assertFalse($this->cr->verify('config', 'tampered'));
    }

    public function testVerifyUnknownKeyIsFalse(): void
    {
        $this->assertFalse($this->cr->verify('ghost', 'x'));
    }

    public function testPutIsIdempotentUpdate(): void
    {
        $this->cr->put('f', 'v1');
        $this->cr->put('f', 'v2');
        $this->assertTrue($this->cr->verify('f', 'v2'));
        $this->assertFalse($this->cr->verify('f', 'v1'));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM checksums')->fetchColumn());
    }

    public function testPutHashAndMatches(): void
    {
        $h = hash('sha256', 'data');
        $this->cr->putHash('artifact', $h);
        $this->assertTrue($this->cr->matches('artifact', $h));
        $this->assertFalse($this->cr->matches('artifact', hash('sha256', 'other')));
    }

    public function testMatchesIsCaseInsensitiveOnHex(): void
    {
        $h = hash('sha256', 'data');
        $this->cr->putHash('artifact', strtoupper($h));
        $this->assertTrue($this->cr->matches('artifact', $h)); // stored lowercased
    }

    public function testGet(): void
    {
        $this->cr->put('f', 'x', 'sha1');
        $row = $this->cr->get('f');
        $this->assertNotNull($row);
        $this->assertSame('sha1', $row['algo']);
        $this->assertSame(hash('sha1', 'x'), $row['checksum']);
    }

    public function testHas(): void
    {
        $this->cr->put('f', 'x');
        $this->assertTrue($this->cr->has('f'));
        $this->assertFalse($this->cr->has('g'));
    }

    public function testVerifyUsesStoredAlgorithm(): void
    {
        $this->cr->put('f', 'payload', 'md5');
        $this->assertTrue($this->cr->verify('f', 'payload')); // re-hashes with md5
    }

    public function testForget(): void
    {
        $this->cr->put('f', 'x');
        $this->cr->forget('f');
        $this->assertFalse($this->cr->has('f'));
    }

    public function testPutRejectsUnknownAlgo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->put('f', 'x', 'notahash');
    }

    public function testPutRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->put('  ', 'x');
    }

    public function testPutHashRejectsEmptyChecksum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cr->putHash('f', '   ');
    }
}
