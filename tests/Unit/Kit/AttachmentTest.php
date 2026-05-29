<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Attachment;
use PDO;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    private PDO $pdo;
    private Attachment $att;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE attachments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                uploaded_by  VARCHAR(255) NOT NULL DEFAULT \'\',
                filename     VARCHAR(255) NOT NULL,
                storage_key  VARCHAR(500) NOT NULL,
                mime_type    VARCHAR(127) NOT NULL DEFAULT \'\',
                size_bytes   BIGINT       NOT NULL DEFAULT 0,
                label        VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->att = new Attachment($this->pdo);
    }

    private function file(array $overrides = []): array
    {
        return array_merge([
            'filename'    => 'screenshot.png',
            'storage_key' => 'uploads/abc123.png',
            'mime_type'   => 'image/png',
            'size_bytes'  => 204800,
            'label'       => 'Bug screenshot',
        ], $overrides);
    }

    // ── attach ────────────────────────────────────────────────────────────────

    public function testAttachReturnsId(): void
    {
        $id = $this->att->attach('issue', '1', 'user-1', $this->file());
        $this->assertGreaterThan(0, $id);
    }

    public function testAttachStoresAllFields(): void
    {
        $id  = $this->att->attach('issue', '1', 'user-1', $this->file());
        $row = $this->att->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('screenshot.png', $row['filename']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('uploads/abc123.png', $row['storage_key']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('image/png', $row['mime_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(204800, (int)$row['size_bytes']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Bug screenshot', $row['label']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['uploaded_by']);
    }

    public function testAttachThrowsOnEmptyFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => '']));
    }

    public function testAttachThrowsOnEmptyStorageKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->att->attach('issue', '1', 'user-1', $this->file(['storage_key' => '']));
    }

    public function testAttachThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->att->attach('', '1', 'user-1', $this->file());
    }

    public function testAttachThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->att->attach('issue', '', 'user-1', $this->file());
    }

    // ── detach ────────────────────────────────────────────────────────────────

    public function testDetachDeletesRow(): void
    {
        $id = $this->att->attach('issue', '1', 'user-1', $this->file());
        $result = $this->att->detach($id);
        $this->assertTrue($result);
        $this->assertNull($this->att->find($id));
    }

    public function testDetachReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->att->detach(9999));
    }

    // ── detachAll ─────────────────────────────────────────────────────────────

    public function testDetachAllDeletesForEntity(): void
    {
        $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'a.png', 'storage_key' => 'a']));
        $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'b.png', 'storage_key' => 'b']));
        $this->att->attach('issue', '2', 'user-1', $this->file(['filename' => 'c.png', 'storage_key' => 'c']));

        $n = $this->att->detachAll('issue', '1');
        $this->assertSame(2, $n);
        $this->assertCount(0, $this->att->forEntity('issue', '1'));
        $this->assertCount(1, $this->att->forEntity('issue', '2'));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->att->find(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsInOrder(): void
    {
        $id1 = $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'a.png', 'storage_key' => 'a']));
        $id2 = $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'b.png', 'storage_key' => 'b']));

        $rows = $this->att->forEntity('issue', '1');
        $this->assertCount(2, $rows);
        $this->assertSame($id1, (int)$rows[0]['id']);
        $this->assertSame($id2, (int)$rows[1]['id']);
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->att->forEntity('issue', '99'));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserListsUserAttachments(): void
    {
        $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'a.png', 'storage_key' => 'a']));
        $this->att->attach('issue', '2', 'user-1', $this->file(['filename' => 'b.png', 'storage_key' => 'b']));
        $this->att->attach('issue', '1', 'user-2', $this->file(['filename' => 'c.png', 'storage_key' => 'c']));

        $rows = $this->att->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    public function testForUserThrowsOnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->att->forUser('');
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsCorrectNumber(): void
    {
        $this->assertSame(0, $this->att->count('issue', '1'));
        $this->att->attach('issue', '1', 'user-1', $this->file(['filename' => 'a.png', 'storage_key' => 'a']));
        $this->assertSame(1, $this->att->count('issue', '1'));
    }

    // ── totalBytes ────────────────────────────────────────────────────────────

    public function testTotalBytesReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->att->totalBytes('issue', '1'));
    }

    public function testTotalBytesSumsAllFiles(): void
    {
        $this->att->attach('issue', '1', 'user-1', $this->file(['size_bytes' => 1000, 'filename' => 'a.png', 'storage_key' => 'a']));
        $this->att->attach('issue', '1', 'user-1', $this->file(['size_bytes' => 2000, 'filename' => 'b.png', 'storage_key' => 'b']));

        $this->assertSame(3000, $this->att->totalBytes('issue', '1'));
    }

    // ── updateLabel ───────────────────────────────────────────────────────────

    public function testUpdateLabelChangesLabel(): void
    {
        $id = $this->att->attach('issue', '1', 'user-1', $this->file(['label' => 'Old']));
        $result = $this->att->updateLabel($id, 'New label');
        $this->assertTrue($result);

        $row = $this->att->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New label', $row['label']);
    }

    public function testUpdateLabelReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->att->updateLabel(9999, 'Label'));
    }
}
