<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TermGlossary;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TermGlossary.
 */
final class TermGlossaryTest extends TestCase
{
    private PDO $db;
    private TermGlossary $g;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE glossary_terms (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slug       VARCHAR(190) NOT NULL,
                term       VARCHAR(190) NOT NULL,
                definition TEXT         NOT NULL,
                category   VARCHAR(100) NOT NULL DEFAULT \'\',
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (slug)
            )
        ');
        $this->g = new TermGlossary($this->db);
    }

    public function testDefineAndGet(): void
    {
        $this->g->define('API', 'Application Programming Interface', 'tech');
        $e = $this->g->get('API');
        $this->assertNotNull($e);
        $this->assertSame('API', $e['term']);
        $this->assertSame('Application Programming Interface', $e['definition']);
        $this->assertSame('tech', $e['category']);
    }

    public function testGetIsCaseInsensitive(): void
    {
        $this->g->define('API', 'def');
        $this->assertNotNull($this->g->get('api'));
        $this->assertNotNull($this->g->get('  Api '));
    }

    public function testDefineIsIdempotentBySlug(): void
    {
        $this->g->define('API', 'first');
        $this->g->define('api', 'second'); // same slug
        $this->assertSame('second', $this->g->get('API')['definition']);
        $this->assertSame(1, $this->g->count());
    }

    public function testHas(): void
    {
        $this->g->define('API', 'def');
        $this->assertTrue($this->g->has('api'));
        $this->assertFalse($this->g->has('SLA'));
    }

    public function testSearchMatchesTermOrDefinition(): void
    {
        $this->g->define('API', 'Application Programming Interface', 'tech');
        $this->g->define('SLA', 'Service Level Agreement', 'ops');
        $this->assertCount(1, $this->g->search('interface')); // in definition
        $this->assertCount(1, $this->g->search('sla'));        // in term
        $this->assertCount(0, $this->g->search('zzz'));
    }

    public function testSearchEmptyQueryReturnsEmpty(): void
    {
        $this->g->define('API', 'def');
        $this->assertSame([], $this->g->search('   '));
    }

    public function testSearchTreatsWildcardLiterally(): void
    {
        $this->g->define('API', 'def');
        // '%' must not act as a wildcard that matches everything
        $this->assertSame([], $this->g->search('%'));
    }

    public function testByCategory(): void
    {
        $this->g->define('API', 'd', 'tech');
        $this->g->define('TCP', 'd', 'tech');
        $this->g->define('SLA', 'd', 'ops');
        $this->assertCount(2, $this->g->byCategory('tech'));
    }

    public function testCategoriesDistinctSorted(): void
    {
        $this->g->define('API', 'd', 'tech');
        $this->g->define('TCP', 'd', 'tech');
        $this->g->define('SLA', 'd', 'ops');
        $this->g->define('FYI', 'd'); // no category
        $this->assertSame(['ops', 'tech'], $this->g->categories());
    }

    public function testAllTermOrder(): void
    {
        $this->g->define('TCP', 'd');
        $this->g->define('API', 'd');
        $all = $this->g->all();
        $this->assertSame('API', $all[0]['term']);
        $this->assertSame('TCP', $all[1]['term']);
    }

    public function testRemove(): void
    {
        $this->g->define('API', 'd');
        $this->g->remove('api');
        $this->assertFalse($this->g->has('API'));
        $this->assertSame(0, $this->g->count());
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->g->remove('ghost');
        $this->assertSame(0, $this->g->count());
    }

    public function testDefineRejectsEmptyTerm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->g->define('   ', 'def');
    }

    public function testDefineRejectsEmptyDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->g->define('API', '   ');
    }
}
