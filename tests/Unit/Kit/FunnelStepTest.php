<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\FunnelStep;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FunnelStep.
 */
final class FunnelStepTest extends TestCase
{
    private PDO $db;
    private FunnelStep $f;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE funnel_steps (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                funnel     VARCHAR(100) NOT NULL,
                subject    VARCHAR(190) NOT NULL,
                step       VARCHAR(100) NOT NULL,
                step_order INTEGER      NOT NULL DEFAULT 0,
                reached_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (funnel, subject, step)
            )
        ');
        $this->f = new FunnelStep($this->db);
    }

    public function testReachAndHasReached(): void
    {
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->assertTrue($this->f->hasReached('signup', 'u1', 'visit'));
        $this->assertFalse($this->f->hasReached('signup', 'u1', 'register'));
    }

    public function testReachIsIdempotent(): void
    {
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->assertCount(1, $this->f->reachedSteps('signup', 'u1'));
    }

    public function testReachedStepsInOrder(): void
    {
        $this->f->reach('signup', 'u1', 'register', 2);
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->f->reach('signup', 'u1', 'activate', 3);
        $this->assertSame(['visit', 'register', 'activate'], $this->f->reachedSteps('signup', 'u1'));
    }

    public function testCountsDistinctSubjectsPerStep(): void
    {
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->f->reach('signup', 'u2', 'visit', 1);
        $this->f->reach('signup', 'u1', 'register', 2);
        $counts = $this->f->counts('signup');
        $this->assertSame('visit', $counts[0]['step']);
        $this->assertSame(2, $counts[0]['subjects']);
        $this->assertSame('register', $counts[1]['step']);
        $this->assertSame(1, $counts[1]['subjects']);
    }

    public function testConversionRate(): void
    {
        // 2 reach visit, 1 of them reaches register → 0.5
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->f->reach('signup', 'u2', 'visit', 1);
        $this->f->reach('signup', 'u1', 'register', 2);
        $this->assertSame(0.5, $this->f->conversionRate('signup', 'visit', 'register'));
    }

    public function testConversionRateFullAndZero(): void
    {
        $this->f->reach('signup', 'u1', 'visit', 1);
        $this->f->reach('signup', 'u1', 'register', 2);
        $this->assertSame(1.0, $this->f->conversionRate('signup', 'visit', 'register'));
        // nobody reached 'activate' among visitors
        $this->assertSame(0.0, $this->f->conversionRate('signup', 'visit', 'activate'));
    }

    public function testConversionRateZeroWhenNoFrom(): void
    {
        $this->assertSame(0.0, $this->f->conversionRate('signup', 'visit', 'register'));
    }

    public function testFunnelsAreSeparate(): void
    {
        $this->f->reach('a', 'u1', 'visit', 1);
        $this->f->reach('b', 'u1', 'visit', 1);
        $this->assertCount(1, $this->f->counts('a'));
        $this->assertFalse($this->f->hasReached('a', 'u2', 'visit'));
    }

    public function testPurgeOlderThan(): void
    {
        $this->f->reach('signup', 'u1', 'visit', 1, '2026-01-01 00:00:00');
        $this->f->reach('signup', 'u2', 'visit', 1, '2026-05-29 00:00:00');
        $removed = $this->f->purgeOlderThan(90, '2026-05-29 00:00:00');
        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->f->counts('signup')[0]['subjects']);
    }

    public function testReachRejectsEmptyFunnel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->f->reach('  ', 'u1', 'visit', 1);
    }

    public function testReachRejectsEmptyStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->f->reach('signup', 'u1', '  ', 1);
    }
}
