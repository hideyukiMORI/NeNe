<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Annotation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Annotation.
 */
final class AnnotationTest extends TestCase
{
    private PDO $db;
    private Annotation $an;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE annotations (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      BIGINT       NOT NULL,
                document     VARCHAR(190) NOT NULL,
                start_offset INTEGER      NOT NULL,
                end_offset   INTEGER      NOT NULL,
                quote        TEXT         NOT NULL DEFAULT \'\',
                note         TEXT         NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->an = new Annotation($this->db);
    }

    public function testAddAndGet(): void
    {
        $id = $this->an->add(1, 'doc', 100, 140, 'quick brown fox', 'nice');
        $a  = $this->an->get($id);
        $this->assertNotNull($a);
        $this->assertSame(1, $a['user_id']);
        $this->assertSame(100, $a['start']);
        $this->assertSame(140, $a['end']);
        $this->assertSame('quick brown fox', $a['quote']);
        $this->assertSame('nice', $a['note']);
    }

    public function testGetMissingIsNull(): void
    {
        $this->assertNull($this->an->get(999));
    }

    public function testForDocumentOrderedByStart(): void
    {
        $this->an->add(1, 'doc', 200, 210);
        $this->an->add(2, 'doc', 50, 60);
        $this->an->add(1, 'doc', 100, 110);
        $starts = array_map(static fn (array $a): int => $a['start'], $this->an->forDocument('doc'));
        $this->assertSame([50, 100, 200], $starts);
    }

    public function testForUser(): void
    {
        $this->an->add(1, 'doc', 10, 20);
        $this->an->add(2, 'doc', 30, 40);
        $this->an->add(1, 'doc', 50, 60);
        $this->assertCount(2, $this->an->forUser(1, 'doc'));
        $this->assertCount(1, $this->an->forUser(2, 'doc'));
    }

    public function testCountFor(): void
    {
        $this->an->add(1, 'doc', 10, 20);
        $this->an->add(2, 'doc', 30, 40);
        $this->assertSame(2, $this->an->countFor('doc'));
        $this->assertSame(0, $this->an->countFor('other'));
    }

    public function testUpdateNote(): void
    {
        $id = $this->an->add(1, 'doc', 10, 20, 'q', 'old');
        $this->assertTrue($this->an->updateNote($id, 'new'));
        $this->assertSame('new', $this->an->get($id)['note']);
        $this->assertFalse($this->an->updateNote(999, 'x')); // missing
    }

    public function testRemove(): void
    {
        $id = $this->an->add(1, 'doc', 10, 20);
        $this->an->remove($id);
        $this->assertNull($this->an->get($id));
        $this->assertSame(0, $this->an->countFor('doc'));
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->an->remove(999);
        $this->assertSame(0, $this->an->countFor('doc'));
    }

    public function testDocumentsAreSeparate(): void
    {
        $this->an->add(1, 'a', 10, 20);
        $this->an->add(1, 'b', 10, 20);
        $this->assertCount(1, $this->an->forDocument('a'));
    }

    public function testAddAllowsZeroStart(): void
    {
        $id = $this->an->add(1, 'doc', 0, 5);
        $this->assertSame(0, $this->an->get($id)['start']);
    }

    public function testAddRejectsEndNotAfterStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->an->add(1, 'doc', 50, 50);
    }

    public function testAddRejectsNegativeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->an->add(1, 'doc', -1, 10);
    }

    public function testAddRejectsEmptyDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->an->add(1, '  ', 0, 10);
    }
}
