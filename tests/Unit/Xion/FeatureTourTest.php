<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FeatureTour;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FeatureTour.
 */
final class FeatureTourTest extends TestCase
{
    private PDO $db;
    private FeatureTour $ft;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE feature_tours (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    BIGINT       NOT NULL,
                tour       VARCHAR(100) NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT \'seen\',
                step       INTEGER      NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, tour)
            )
        ');
        $this->ft = new FeatureTour($this->db);
    }

    public function testShouldShowWhenPristine(): void
    {
        $this->assertTrue($this->ft->shouldShow(42, 'editor'));
        $this->assertNull($this->ft->status(42, 'editor'));
        $this->assertSame(0, $this->ft->step(42, 'editor'));
    }

    public function testMarkSeenStopsAutoDisplay(): void
    {
        $this->ft->markSeen(42, 'editor');
        $this->assertFalse($this->ft->shouldShow(42, 'editor'));
        $this->assertSame('seen', $this->ft->status(42, 'editor'));
    }

    public function testAdvanceRecordsStep(): void
    {
        $this->ft->markSeen(42, 'editor');
        $this->ft->advance(42, 'editor', 3);
        $this->assertSame(3, $this->ft->step(42, 'editor'));
        $this->assertSame('seen', $this->ft->status(42, 'editor'));
    }

    public function testAdvanceCreatesRecord(): void
    {
        $this->ft->advance(42, 'editor', 2);
        $this->assertFalse($this->ft->shouldShow(42, 'editor'));
        $this->assertSame(2, $this->ft->step(42, 'editor'));
    }

    public function testComplete(): void
    {
        $this->ft->markSeen(42, 'editor');
        $this->ft->complete(42, 'editor');
        $this->assertSame('completed', $this->ft->status(42, 'editor'));
    }

    public function testDismiss(): void
    {
        $this->ft->dismiss(42, 'editor');
        $this->assertSame('dismissed', $this->ft->status(42, 'editor'));
    }

    public function testMarkSeenDoesNotRegressCompleted(): void
    {
        $this->ft->complete(42, 'editor');
        $this->ft->markSeen(42, 'editor'); // should not pull back to 'seen'
        $this->assertSame('completed', $this->ft->status(42, 'editor'));
    }

    public function testMarkSeenDoesNotRegressDismissed(): void
    {
        $this->ft->dismiss(42, 'editor');
        $this->ft->advance(42, 'editor', 5);
        $this->assertSame('dismissed', $this->ft->status(42, 'editor'));
    }

    public function testReset(): void
    {
        $this->ft->complete(42, 'editor');
        $this->ft->reset(42, 'editor');
        $this->assertTrue($this->ft->shouldShow(42, 'editor'));
    }

    public function testResetAllReShowsToEveryone(): void
    {
        $this->ft->complete(1, 'editor');
        $this->ft->dismiss(2, 'editor');
        $this->ft->markSeen(3, 'other');
        $removed = $this->ft->resetAll('editor');
        $this->assertSame(2, $removed);
        $this->assertTrue($this->ft->shouldShow(1, 'editor'));
        $this->assertTrue($this->ft->shouldShow(2, 'editor'));
        $this->assertFalse($this->ft->shouldShow(3, 'other')); // untouched
    }

    public function testCountsByStatus(): void
    {
        $this->ft->complete(1, 'editor');
        $this->ft->complete(2, 'editor');
        $this->ft->dismiss(3, 'editor');
        $this->assertSame(2, $this->ft->completedCount('editor'));
        $this->assertSame(1, $this->ft->dismissedCount('editor'));
    }

    public function testToursAreSeparatePerUser(): void
    {
        $this->ft->complete(1, 'editor');
        $this->assertTrue($this->ft->shouldShow(2, 'editor'));
    }

    public function testAdvanceRejectsNegativeStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ft->advance(42, 'editor', -1);
    }

    public function testMarkSeenRejectsEmptyTour(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ft->markSeen(42, '  ');
    }
}
