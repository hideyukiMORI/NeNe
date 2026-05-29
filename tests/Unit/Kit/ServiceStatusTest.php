<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ServiceStatus;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ServiceStatus.
 */
final class ServiceStatusTest extends TestCase
{
    private PDO $db;
    private ServiceStatus $ss;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE service_status (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                component  VARCHAR(150) NOT NULL,
                status     VARCHAR(20)  NOT NULL,
                message    VARCHAR(255) NOT NULL DEFAULT \'\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (component)
            )
        ');
        $this->ss = new ServiceStatus($this->db);
    }

    public function testSetAndStatusOf(): void
    {
        $this->ss->setStatus('api', ServiceStatus::DEGRADED, 'slow');
        $this->assertSame('degraded', $this->ss->statusOf('api'));
        $this->assertNull($this->ss->statusOf('web'));
    }

    public function testOverallEmptyIsOperational(): void
    {
        $this->assertSame(ServiceStatus::OPERATIONAL, $this->ss->overall());
        $this->assertTrue($this->ss->isOperational());
    }

    public function testOverallAllOperational(): void
    {
        $this->ss->setStatus('api', ServiceStatus::OPERATIONAL);
        $this->ss->setStatus('web', ServiceStatus::OPERATIONAL);
        $this->assertTrue($this->ss->isOperational());
    }

    public function testOverallReturnsWorst(): void
    {
        $this->ss->setStatus('api', ServiceStatus::DEGRADED);
        $this->ss->setStatus('web', ServiceStatus::OPERATIONAL);
        $this->ss->setStatus('db', ServiceStatus::MAJOR_OUTAGE);
        $this->assertSame(ServiceStatus::MAJOR_OUTAGE, $this->ss->overall());
        $this->assertFalse($this->ss->isOperational());
    }

    public function testSeverityOrdering(): void
    {
        $this->ss->setStatus('a', ServiceStatus::MAINTENANCE);
        $this->ss->setStatus('b', ServiceStatus::PARTIAL_OUTAGE);
        // partial_outage outranks maintenance
        $this->assertSame(ServiceStatus::PARTIAL_OUTAGE, $this->ss->overall());
    }

    public function testMaintenanceOutranksOperational(): void
    {
        $this->ss->setStatus('a', ServiceStatus::OPERATIONAL);
        $this->ss->setStatus('b', ServiceStatus::MAINTENANCE);
        $this->assertSame(ServiceStatus::MAINTENANCE, $this->ss->overall());
        $this->assertFalse($this->ss->isOperational());
    }

    public function testComponentsListed(): void
    {
        $this->ss->setStatus('web', ServiceStatus::OPERATIONAL, 'ok');
        $this->ss->setStatus('api', ServiceStatus::DEGRADED, 'slow');
        $comps = $this->ss->components();
        $this->assertSame('api', $comps[0]['component']); // ordered by name
        $this->assertSame('slow', $comps[0]['message']);
    }

    public function testSetStatusIsIdempotent(): void
    {
        $this->ss->setStatus('api', ServiceStatus::DEGRADED);
        $this->ss->setStatus('api', ServiceStatus::OPERATIONAL);
        $this->assertSame('operational', $this->ss->statusOf('api'));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM service_status')->fetchColumn());
    }

    public function testRemove(): void
    {
        $this->ss->setStatus('api', ServiceStatus::MAJOR_OUTAGE);
        $this->ss->remove('api');
        $this->assertNull($this->ss->statusOf('api'));
        $this->assertTrue($this->ss->isOperational()); // back to empty/operational
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->ss->remove('ghost');
        $this->assertSame([], $this->ss->components());
    }

    public function testSetStatusRejectsUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->setStatus('api', 'exploded');
    }

    public function testSetStatusRejectsEmptyComponent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ss->setStatus('  ', ServiceStatus::OPERATIONAL);
    }
}
