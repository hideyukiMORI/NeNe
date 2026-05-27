<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ApiWebhook;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiWebhookTest extends TestCase
{
    private PDO $pdo;
    private ApiWebhook $aw;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE api_webhooks (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type    VARCHAR(100) NOT NULL,
                endpoint_url  TEXT         NOT NULL,
                secret        VARCHAR(64)  NOT NULL,
                active        TINYINT(1)   NOT NULL DEFAULT 1,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->aw = new ApiWebhook($this->pdo);
    }

    // ── subscribe ─────────────────────────────────────────────────────────────

    public function testSubscribeReturnsIdAndSecret(): void
    {
        $result = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('secret', $result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame(64, strlen($result['secret']));
    }

    public function testSubscribeStoresRow(): void
    {
        $result = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $row    = $this->aw->get($result['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order.created', $row['event_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('https://example.com/hook', $row['endpoint_url']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    public function testSubscribeThrowsOnEmptyEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aw->subscribe('', 'https://example.com/hook');
    }

    public function testSubscribeThrowsOnEmptyEndpointUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->aw->subscribe('order.created', '');
    }

    public function testSubscribeGeneratesUniqueSecrets(): void
    {
        $r1 = $this->aw->subscribe('order.created', 'https://a.com/hook');
        $r2 = $this->aw->subscribe('order.created', 'https://b.com/hook');
        $this->assertNotSame($r1['secret'], $r2['secret']);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->aw->get(9999));
    }

    // ── unsubscribe ───────────────────────────────────────────────────────────

    public function testUnsubscribeDeletesRow(): void
    {
        $r = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $this->assertTrue($this->aw->unsubscribe($r['id']));
        $this->assertNull($this->aw->get($r['id']));
    }

    public function testUnsubscribeReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->aw->unsubscribe(9999));
    }

    // ── rotateSecret ──────────────────────────────────────────────────────────

    public function testRotateSecretReturnsNewSecret(): void
    {
        $r         = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $newSecret = $this->aw->rotateSecret($r['id']);
        $this->assertNotNull($newSecret);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal
        $this->assertSame(64, strlen($newSecret));
        $this->assertNotSame($r['secret'], $newSecret);
    }

    public function testRotateSecretUpdatesStoredValue(): void
    {
        $r         = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $newSecret = $this->aw->rotateSecret($r['id']);
        $row       = $this->aw->get($r['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($newSecret, $row['secret']);
    }

    public function testRotateSecretReturnsNullForMissingId(): void
    {
        $this->assertNull($this->aw->rotateSecret(9999));
    }

    // ── activate / deactivate ─────────────────────────────────────────────────

    public function testDeactivateHidesFromForEvent(): void
    {
        $r = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $this->aw->deactivate($r['id']);
        $this->assertCount(0, $this->aw->forEvent('order.created'));
    }

    public function testActivateRestoresInForEvent(): void
    {
        $r = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $this->aw->deactivate($r['id']);
        $this->aw->activate($r['id']);
        $this->assertCount(1, $this->aw->forEvent('order.created'));
    }

    // ── forEvent ──────────────────────────────────────────────────────────────

    public function testForEventReturnsActiveSubscribers(): void
    {
        $this->aw->subscribe('order.created', 'https://a.com/hook');
        $this->aw->subscribe('order.created', 'https://b.com/hook');
        $this->aw->subscribe('user.registered', 'https://c.com/hook');
        $hooks = $this->aw->forEvent('order.created');
        $this->assertCount(2, $hooks);
    }

    public function testForEventReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->aw->forEvent('nonexistent'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllIncludesInactive(): void
    {
        $r = $this->aw->subscribe('order.created', 'https://example.com/hook');
        $this->aw->deactivate($r['id']);
        $all = $this->aw->all();
        $this->assertCount(1, $all);
        $this->assertSame(0, (int)$all[0]['active']);
    }
}
