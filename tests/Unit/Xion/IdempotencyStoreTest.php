<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\IdempotencyStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IdempotencyStore (FT37).
 *
 * Uses SQLite :memory: so no external database or container is required.
 * The table is created fresh for every test via setUp().
 */
final class IdempotencyStoreTest extends TestCase
{
    private PDO $pdo;
    private IdempotencyStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE idempotency_keys (
                key_hash    VARCHAR(64) PRIMARY KEY,
                status_code SMALLINT    NOT NULL,
                body        TEXT        NOT NULL,
                created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->store = new IdempotencyStore($this->pdo);
    }

    // ------------------------------------------------------------------ //
    // get() — missing key
    // ------------------------------------------------------------------ //

    public function testGetReturnsNullForUnknownKey(): void
    {
        $result = $this->store->get('no-such-key');

        self::assertNull($result);
    }

    // ------------------------------------------------------------------ //
    // put() + get() round-trip
    // ------------------------------------------------------------------ //

    public function testPutThenGetReturnsSameBodyAndStatusCode(): void
    {
        $this->store->put('my-key', 201, '{"id":42}');

        $cached = $this->store->get('my-key');

        self::assertNotNull($cached);
        self::assertSame(201, $cached['status_code']);
        self::assertSame('{"id":42}', $cached['body']);
    }

    public function testPutThenGetWith200StatusCode(): void
    {
        $this->store->put('another-key', 200, '{"ok":true}');

        $cached = $this->store->get('another-key');

        self::assertNotNull($cached);
        self::assertSame(200, $cached['status_code']);
        self::assertSame('{"ok":true}', $cached['body']);
    }

    // ------------------------------------------------------------------ //
    // put() idempotency — second put does not overwrite
    // ------------------------------------------------------------------ //

    public function testSecondPutDoesNotOverwriteFirstValue(): void
    {
        $this->store->put('dup-key', 201, '{"version":"first"}');
        $this->store->put('dup-key', 500, '{"version":"second"}');

        $cached = $this->store->get('dup-key');

        self::assertNotNull($cached);
        self::assertSame(201, $cached['status_code']);
        self::assertSame('{"version":"first"}', $cached['body']);
    }

    // ------------------------------------------------------------------ //
    // hash()
    // ------------------------------------------------------------------ //

    public function testHashReturnsSixtyFourCharHex(): void
    {
        $result = $this->store->hash('some-idempotency-key');

        self::assertSame(64, strlen($result));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function testHashIsDeterministic(): void
    {
        $key = 'deterministic-key';

        self::assertSame($this->store->hash($key), $this->store->hash($key));
    }

    public function testHashDifferentKeysProduceDifferentHashes(): void
    {
        self::assertNotSame(
            $this->store->hash('key-a'),
            $this->store->hash('key-b')
        );
    }

    // ------------------------------------------------------------------ //
    // Key isolation — different raw keys are independent
    // ------------------------------------------------------------------ //

    public function testDifferentKeysAreStoredAndRetrievedIndependently(): void
    {
        $this->store->put('key-x', 201, '{"x":1}');
        $this->store->put('key-y', 200, '{"y":2}');

        $x = $this->store->get('key-x');
        $y = $this->store->get('key-y');

        self::assertNotNull($x);
        self::assertNotNull($y);
        self::assertSame('{"x":1}', $x['body']);
        self::assertSame('{"y":2}', $y['body']);
    }
}
