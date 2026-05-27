<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\AddressBook;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AddressBook.
 */
final class AddressBookTest extends TestCase
{
    private PDO $db;
    private AddressBook $book;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE addresses (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                label      VARCHAR(100) NOT NULL DEFAULT \'\',
                name       VARCHAR(255) NOT NULL DEFAULT \'\',
                line1      VARCHAR(255) NOT NULL DEFAULT \'\',
                line2      VARCHAR(255) NOT NULL DEFAULT \'\',
                city       VARCHAR(100) NOT NULL DEFAULT \'\',
                state      VARCHAR(100) NOT NULL DEFAULT \'\',
                postal     VARCHAR(20)  NOT NULL DEFAULT \'\',
                country    VARCHAR(2)   NOT NULL DEFAULT \'\',
                phone      VARCHAR(50)  NOT NULL DEFAULT \'\',
                is_default TINYINT(1)   NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->book = new AddressBook($this->db);
    }

    private function sampleData(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Home',
            'name'       => 'Taro Yamada',
            'line1'      => '1-2-3 Shinjuku',
            'line2'      => '',
            'city'       => 'Shinjuku-ku',
            'state'      => 'Tokyo',
            'postal'     => '160-0022',
            'country'    => 'JP',
            'phone'      => '+81-90-0000-0000',
            'is_default' => false,
        ], $overrides);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsIntId(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresAllFields(): void
    {
        $id  = $this->book->add('user-1', $this->sampleData());
        $row = $this->book->get('user-1', $id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('Home', $row['label']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('Taro Yamada', $row['name']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('1-2-3 Shinjuku', $row['line1']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('JP', $row['country']);
    }

    public function testAddThrowsIfLine1Empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->book->add('user-1', $this->sampleData(['line1' => '']));
    }

    public function testAddThrowsIfCountryEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->book->add('user-1', $this->sampleData(['country' => '']));
    }

    public function testAddWithIsDefaultSetsDefaultFlag(): void
    {
        $id  = $this->book->add('user-1', $this->sampleData(['is_default' => true]));
        $row = $this->book->get('user-1', $id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(1, (int)$row['is_default']);
    }

    public function testAddSecondDefaultClearsPreviousDefault(): void
    {
        $id1 = $this->book->add('user-1', $this->sampleData(['is_default' => true]));
        $id2 = $this->book->add('user-1', $this->sampleData(['label' => 'Work', 'is_default' => true]));

        $row1 = $this->book->get('user-1', $id1);
        $row2 = $this->book->get('user-1', $id2);
        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(0, (int)$row1['is_default'], 'old default should be cleared');
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(1, (int)$row2['is_default'], 'new default should be set');
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllAddresses(): void
    {
        $this->book->add('user-1', $this->sampleData(['label' => 'A']));
        $this->book->add('user-1', $this->sampleData(['label' => 'B']));
        $this->book->add('user-1', $this->sampleData(['label' => 'C']));

        $list = $this->book->list('user-1');
        $this->assertCount(3, $list);
    }

    public function testListReturnsEmptyForUnknownUser(): void
    {
        $list = $this->book->list('no-such-user');
        $this->assertSame([], $list);
    }

    public function testListDefaultAddressComesFirst(): void
    {
        $this->book->add('user-1', $this->sampleData(['label' => 'A']));
        $id2 = $this->book->add('user-1', $this->sampleData(['label' => 'B', 'is_default' => true]));
        $this->book->add('user-1', $this->sampleData(['label' => 'C']));

        $list = $this->book->list('user-1');
        $this->assertSame((string)$id2, (string)$list[0]['id']);
    }

    public function testListIsolatedBetweenUsers(): void
    {
        $this->book->add('user-1', $this->sampleData());
        $this->book->add('user-2', $this->sampleData(['label' => 'Work']));

        $this->assertCount(1, $this->book->list('user-1'));
        $this->assertCount(1, $this->book->list('user-2'));
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $result = $this->book->get('user-1', 9999);
        $this->assertNull($result);
    }

    public function testGetDoesNotCrossUserBoundary(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $result = $this->book->get('user-2', $id);
        $this->assertNull($result);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesFields(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->book->update('user-1', $id, ['label' => 'Office', 'city' => 'Osaka']);

        $row = $this->book->get('user-1', $id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('Office', $row['label']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame('Osaka', $row['city']);
    }

    public function testUpdateReturnsFalseForUnknownId(): void
    {
        $result = $this->book->update('user-1', 9999, ['label' => 'X']);
        $this->assertFalse($result);
    }

    public function testUpdateThrowsIfLine1SetToEmpty(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->expectException(\InvalidArgumentException::class);
        $this->book->update('user-1', $id, ['line1' => '']);
    }

    public function testUpdateIsDefaultClearsPreviousDefault(): void
    {
        $id1 = $this->book->add('user-1', $this->sampleData(['is_default' => true]));
        $id2 = $this->book->add('user-1', $this->sampleData(['label' => 'Work']));

        $this->book->update('user-1', $id2, ['is_default' => true]);

        $row1 = $this->book->get('user-1', $id1);
        $row2 = $this->book->get('user-1', $id2);
        $this->assertNotNull($row1);
        $this->assertNotNull($row2);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(0, (int)$row1['is_default']);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(1, (int)$row2['is_default']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesAddress(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->assertTrue($this->book->remove('user-1', $id));
        $this->assertNull($this->book->get('user-1', $id));
    }

    public function testRemoveReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->book->remove('user-1', 9999));
    }

    public function testRemoveDoesNotCrossUserBoundary(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->assertFalse($this->book->remove('user-2', $id));
        $this->assertNotNull($this->book->get('user-1', $id));
    }

    // ── setDefault / getDefault ───────────────────────────────────────────────

    public function testSetDefaultMarksAddress(): void
    {
        $id = $this->book->add('user-1', $this->sampleData());
        $this->assertTrue($this->book->setDefault('user-1', $id));

        $row = $this->book->get('user-1', $id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(1, (int)$row['is_default']);
    }

    public function testSetDefaultClearsPreviousDefault(): void
    {
        $id1 = $this->book->add('user-1', $this->sampleData(['is_default' => true]));
        $id2 = $this->book->add('user-1', $this->sampleData(['label' => 'Work']));

        $this->book->setDefault('user-1', $id2);

        $row1 = $this->book->get('user-1', $id1);
        $this->assertNotNull($row1);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame(0, (int)$row1['is_default']);
    }

    public function testGetDefaultReturnsNullWhenNoneSet(): void
    {
        $this->book->add('user-1', $this->sampleData());
        $this->assertNull($this->book->getDefault('user-1'));
    }

    public function testGetDefaultReturnsDefaultAddress(): void
    {
        $id = $this->book->add('user-1', $this->sampleData(['is_default' => true]));
        $def = $this->book->getDefault('user-1');
        $this->assertNotNull($def);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertSame((string)$id, (string)$def['id']);
    }

    public function testSetDefaultReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->book->setDefault('user-1', 9999));
    }
}
