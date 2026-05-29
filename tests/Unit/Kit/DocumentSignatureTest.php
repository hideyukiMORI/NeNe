<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DocumentSignature;
use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentSignatureTest extends TestCase
{
    private PDO $pdo;
    private DocumentSignature $ds;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE doc_signature_requests (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_id VARCHAR(255) NOT NULL,
                document_ref VARCHAR(255) NOT NULL,
                title        TEXT         NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE doc_signature_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id   INTEGER      NOT NULL,
                signatory_id VARCHAR(255) NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                ip_address   VARCHAR(45)  NULL,
                signed_at    DATETIME     NULL
            )
        ');
        $this->ds = new DocumentSignature($this->pdo);
    }

    // ── request ───────────────────────────────────────────────────────────────

    public function testRequestReturnsId(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->assertGreaterThan(0, $id);
    }

    public function testRequestStoresFields(): void
    {
        $id  = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $row = $this->ds->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['requester_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('NDA-001', $row['document_ref']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DocumentSignature::STATUS_PENDING, $row['status']);
    }

    public function testRequestCreatesSignatoryItems(): void
    {
        $id    = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $items = $this->ds->items($id);
        $this->assertCount(2, $items);
        $this->assertSame(DocumentSignature::ITEM_PENDING, $items[0]['status']);
    }

    public function testRequestThrowsOnEmptyRequesterId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ds->request('', 'NDA-001', 'NDA 2026', ['user-2']);
    }

    public function testRequestThrowsOnEmptyDocumentRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ds->request('user-1', '', 'NDA 2026', ['user-2']);
    }

    public function testRequestThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ds->request('user-1', 'NDA-001', '', ['user-2']);
    }

    public function testRequestThrowsOnEmptySignatories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ds->request('user-1', 'NDA-001', 'NDA 2026', []);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->ds->get(9999));
    }

    // ── sign ──────────────────────────────────────────────────────────────────

    public function testSignMarksItemSigned(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->assertTrue($this->ds->sign($id, 'user-2', '127.0.0.1'));
        $items = $this->ds->items($id);
        $this->assertSame(DocumentSignature::ITEM_SIGNED, $items[0]['status']);
        $this->assertSame('127.0.0.1', $items[0]['ip_address']);
        $this->assertNotNull($items[0]['signed_at']);
    }

    public function testSignCompletesRequestWhenAllSigned(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->ds->sign($id, 'user-2');
        $this->ds->sign($id, 'user-3');
        $this->assertTrue($this->ds->isComplete($id));
        $row = $this->ds->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DocumentSignature::STATUS_COMPLETE, $row['status']);
    }

    public function testSignDoesNotCompleteWhenSomeStillPending(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->ds->sign($id, 'user-2');
        $this->assertFalse($this->ds->isComplete($id));
    }

    public function testSignReturnsFalseForNonPendingItem(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->ds->sign($id, 'user-2');
        $this->assertFalse($this->ds->sign($id, 'user-2')); // already signed
    }

    public function testSignReturnsFalseForUnknownSignatory(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->assertFalse($this->ds->sign($id, 'user-99'));
    }

    // ── decline ───────────────────────────────────────────────────────────────

    public function testDeclineMarksItemAndCancelsRequest(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->assertTrue($this->ds->decline($id, 'user-2'));
        $items = $this->ds->items($id);
        $declinedItem = array_filter($items, fn ($i) => $i['signatory_id'] === 'user-2');
        $this->assertSame(DocumentSignature::ITEM_DECLINED, array_values($declinedItem)[0]['status']);
        $row = $this->ds->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DocumentSignature::STATUS_CANCELLED, $row['status']);
    }

    public function testDeclineReturnsFalseForAlreadySigned(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->ds->sign($id, 'user-2');
        $this->assertFalse($this->ds->decline($id, 'user-2'));
    }

    // ── isComplete / pendingCount ─────────────────────────────────────────────

    public function testIsCompleteReturnsFalseInitially(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->assertFalse($this->ds->isComplete($id));
    }

    public function testPendingCountDecreasesOnSign(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->assertSame(2, $this->ds->pendingCount($id));
        $this->ds->sign($id, 'user-2');
        $this->assertSame(1, $this->ds->pendingCount($id));
    }

    // ── forDocument ───────────────────────────────────────────────────────────

    public function testForDocumentFilters(): void
    {
        $this->ds->request('user-1', 'NDA-001', 'NDA v1', ['user-2']);
        $this->ds->request('user-1', 'NDA-001', 'NDA v2', ['user-3']);
        $this->ds->request('user-1', 'MSA-001', 'MSA', ['user-4']);
        $this->assertCount(2, $this->ds->forDocument('NDA-001'));
        $this->assertCount(1, $this->ds->forDocument('MSA-001'));
    }

    // ── pendingFor ────────────────────────────────────────────────────────────

    public function testPendingForFilters(): void
    {
        $id1 = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->ds->request('user-1', 'MSA-001', 'MSA 2026', ['user-3']);
        $this->assertCount(1, $this->ds->pendingFor('user-2'));
        $this->assertCount(2, $this->ds->pendingFor('user-3'));
        $this->ds->sign($id1, 'user-3');
        $this->assertCount(1, $this->ds->pendingFor('user-3'));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelChangesPendingToCancelled(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->assertTrue($this->ds->cancel($id));
        $row = $this->ds->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DocumentSignature::STATUS_CANCELLED, $row['status']);
    }

    public function testCancelReturnsFalseWhenAlreadyComplete(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2']);
        $this->ds->sign($id, 'user-2');
        $this->assertFalse($this->ds->cancel($id));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRequestAndItems(): void
    {
        $id = $this->ds->request('user-1', 'NDA-001', 'NDA 2026', ['user-2', 'user-3']);
        $this->assertTrue($this->ds->delete($id));
        $this->assertNull($this->ds->get($id));
        $this->assertSame([], $this->ds->items($id));
    }

    public function testDeleteReturnsFalseForMissingRequest(): void
    {
        $this->assertFalse($this->ds->delete(9999));
    }
}
