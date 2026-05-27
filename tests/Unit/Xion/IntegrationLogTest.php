<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\IntegrationLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class IntegrationLogTest extends TestCase
{
    private PDO $pdo;
    private IntegrationLog $log;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE integration_logs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                service        VARCHAR(100) NOT NULL,
                endpoint       TEXT         NOT NULL,
                method         VARCHAR(10)  NOT NULL DEFAULT \'GET\',
                request_body   TEXT         NULL,
                http_status    INTEGER      NULL,
                response_body  TEXT         NULL,
                duration_ms    INTEGER      NULL,
                error_message  TEXT         NULL,
                created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->log = new IntegrationLog($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->log->record('stripe', '/v1/charges', 'POST');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $id = $this->log->record('stripe', '/v1/charges', 'POST', ['amount' => 500], 200, '{"id":"ch_1"}', 230);
        $entry = $this->log->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('stripe', $entry['service']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('/v1/charges', $entry['endpoint']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('POST', $entry['method']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(200, (int)$entry['http_status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"id":"ch_1"}', $entry['response_body']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(230, (int)$entry['duration_ms']);
    }

    public function testRecordArrayRequestBodyEncodedAsJson(): void
    {
        $id = $this->log->record('svc', '/ep', 'POST', ['key' => 'val']);
        $entry = $this->log->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('{"key":"val"}', $entry['request_body']);
    }

    public function testRecordStringRequestBody(): void
    {
        $id = $this->log->record('svc', '/ep', 'POST', 'raw-body');
        $entry = $this->log->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('raw-body', $entry['request_body']);
    }

    public function testRecordUppercasesMethod(): void
    {
        $id = $this->log->record('svc', '/ep', 'post');
        $entry = $this->log->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('POST', $entry['method']);
    }

    public function testRecordWithErrorMessage(): void
    {
        $id = $this->log->record('svc', '/ep', 'GET', null, null, null, null, 'Connection refused');
        $entry = $this->log->find($id);
        $this->assertNotNull($entry);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Connection refused', $entry['error_message']);
    }

    public function testRecordThrowsOnEmptyService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->record('', '/ep');
    }

    public function testRecordThrowsOnEmptyEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->record('svc', '');
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->log->find(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesEntry(): void
    {
        $id = $this->log->record('svc', '/ep');
        $this->assertTrue($this->log->delete($id));
        $this->assertNull($this->log->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->log->delete(9999));
    }

    // ── forService ────────────────────────────────────────────────────────────

    public function testForServiceReturnsNewestFirst(): void
    {
        $id1 = $this->log->record('stripe', '/a');
        $id2 = $this->log->record('stripe', '/b');
        $entries = $this->log->forService('stripe');
        $this->assertCount(2, $entries);
        $this->assertSame($id2, (int)$entries[0]['id']);
        $this->assertSame($id1, (int)$entries[1]['id']);
    }

    public function testForServiceIsIsolatedByService(): void
    {
        $this->log->record('stripe', '/a');
        $this->log->record('twilio', '/b');
        $this->assertCount(1, $this->log->forService('stripe'));
        $this->assertCount(1, $this->log->forService('twilio'));
    }

    public function testForServiceReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->log->forService('unknown'));
    }

    public function testForServiceRespectsLimitOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->log->record('svc', '/ep');
        }
        $page1 = $this->log->forService('svc', 3, 0);
        $page2 = $this->log->forService('svc', 3, 3);
        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── errors ────────────────────────────────────────────────────────────────

    public function testErrorsReturnsEntriesWithErrorMessage(): void
    {
        $this->log->record('svc', '/ok', 'GET', null, 200);
        $errId = $this->log->record('svc', '/fail', 'GET', null, null, null, null, 'Timeout');
        $errors = $this->log->errors('svc');
        $this->assertCount(1, $errors);
        $this->assertSame($errId, (int)$errors[0]['id']);
    }

    public function testErrorsReturnsEntriesWithNon2xxStatus(): void
    {
        $this->log->record('svc', '/ok', 'GET', null, 200);
        $e404 = $this->log->record('svc', '/missing', 'GET', null, 404);
        $e500 = $this->log->record('svc', '/broken', 'GET', null, 500);
        $errors = $this->log->errors('svc');
        $this->assertCount(2, $errors);
        $ids = array_map('intval', array_column($errors, 'id'));
        $this->assertContains($e404, $ids);
        $this->assertContains($e500, $ids);
    }

    public function testErrorsReturnsEmptyWhenAllSuccessful(): void
    {
        $this->log->record('svc', '/a', 'GET', null, 200);
        $this->log->record('svc', '/b', 'POST', null, 201);
        $this->assertSame([], $this->log->errors('svc'));
    }

    // ── countErrors ───────────────────────────────────────────────────────────

    public function testCountErrors(): void
    {
        $this->log->record('svc', '/ok', 'GET', null, 200);
        $this->log->record('svc', '/fail', 'GET', null, 500);
        $this->log->record('svc', '/err', 'GET', null, null, null, null, 'Timeout');
        $this->assertSame(2, $this->log->countErrors('svc'));
    }

    public function testCountErrorsReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->log->countErrors('svc'));
    }

    // ── services ─────────────────────────────────────────────────────────────

    public function testServicesReturnsDistinctNames(): void
    {
        $this->log->record('stripe', '/a');
        $this->log->record('stripe', '/b');
        $this->log->record('twilio', '/c');
        $services = $this->log->services();
        $this->assertCount(2, $services);
        $this->assertContains('stripe', $services);
        $this->assertContains('twilio', $services);
    }

    public function testServicesReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->log->services());
    }

    // ── deleteOlderThan ───────────────────────────────────────────────────────

    public function testDeleteOlderThan(): void
    {
        // Insert with explicit old timestamp
        $this->pdo->exec("INSERT INTO integration_logs (service, endpoint, created_at) VALUES ('svc', '/old', '2020-01-01 00:00:00')");
        $this->log->record('svc', '/new');

        $cutoff = new \DateTimeImmutable('2021-01-01 00:00:00');
        $count = $this->log->deleteOlderThan($cutoff);
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->log->forService('svc'));
    }

    public function testDeleteOlderThanReturnsZeroWhenNone(): void
    {
        $cutoff = new \DateTimeImmutable('2000-01-01 00:00:00');
        $this->assertSame(0, $this->log->deleteOlderThan($cutoff));
    }
}
