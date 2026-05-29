<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TwoFactorBackupCode;
use PDO;
use PHPUnit\Framework\TestCase;

final class TwoFactorBackupCodeTest extends TestCase
{
    private PDO $pdo;
    private TwoFactorBackupCode $bc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE two_factor_backup_codes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL,
                code_hash  VARCHAR(64)  NOT NULL,
                used       TINYINT(1)   NOT NULL DEFAULT 0,
                used_at    DATETIME     NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->bc = new TwoFactorBackupCode($this->pdo);
    }

    // ── generate ──────────────────────────────────────────────────────────────

    public function testGenerateReturnsRawCodes(): void
    {
        $codes = $this->bc->generate('user-1', 10);
        $this->assertCount(10, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{5}-[0-9a-f]{5}$/', $code);
        }
    }

    public function testGenerateCodesAreUnique(): void
    {
        $codes = $this->bc->generate('user-1', 10);
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testGenerateStoresHashedCodes(): void
    {
        $codes = $this->bc->generate('user-1', 5);
        $this->assertSame(5, $this->bc->remaining('user-1'));

        // Verify raw codes verify against stored hashes
        foreach ($codes as $code) {
            $hash = hash('sha256', $code);
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM two_factor_backup_codes WHERE user_id = ? AND code_hash = ?'
            );
            $stmt->execute(['user-1', $hash]);
            $this->assertSame(1, (int)$stmt->fetchColumn());
        }
    }

    public function testGenerateInvalidatesPreviousCodes(): void
    {
        $this->bc->generate('user-1', 5);
        $newCodes = $this->bc->generate('user-1', 3);

        $this->assertSame(3, $this->bc->remaining('user-1'));
        $this->assertCount(3, $newCodes);
    }

    public function testGenerateThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->generate('');
    }

    public function testGenerateThrowsOnCountOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->generate('user-1', 21);
    }

    public function testGenerateThrowsOnZeroCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bc->generate('user-1', 0);
    }

    // ── consume ───────────────────────────────────────────────────────────────

    public function testConsumeValidCodeReturnsTrue(): void
    {
        $codes = $this->bc->generate('user-1');
        $result = $this->bc->consume('user-1', $codes[0]);
        $this->assertTrue($result);
    }

    public function testConsumeDecrementsRemaining(): void
    {
        $codes = $this->bc->generate('user-1', 3);
        $this->bc->consume('user-1', $codes[0]);
        $this->assertSame(2, $this->bc->remaining('user-1'));
    }

    public function testConsumeCodeIsOneTimeOnly(): void
    {
        $codes = $this->bc->generate('user-1', 3);
        $this->bc->consume('user-1', $codes[0]);
        $this->assertFalse($this->bc->consume('user-1', $codes[0]));
    }

    public function testConsumeInvalidCodeReturnsFalse(): void
    {
        $this->bc->generate('user-1', 3);
        $this->assertFalse($this->bc->consume('user-1', 'xxxxx-yyyyy'));
    }

    public function testConsumeWrongUserReturnsFalse(): void
    {
        $codes = $this->bc->generate('user-1', 3);
        $this->assertFalse($this->bc->consume('user-2', $codes[0]));
    }

    // ── remaining ─────────────────────────────────────────────────────────────

    public function testRemainingReturnsZeroWithNoCodes(): void
    {
        $this->assertSame(0, $this->bc->remaining('user-1'));
    }

    // ── invalidateAll ─────────────────────────────────────────────────────────

    public function testInvalidateAllDeletesAllCodes(): void
    {
        $this->bc->generate('user-1', 5);
        $n = $this->bc->invalidateAll('user-1');
        $this->assertSame(5, $n);
        $this->assertSame(0, $this->bc->remaining('user-1'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsAllCodesWithoutHash(): void
    {
        $this->bc->generate('user-1', 3);
        $list = $this->bc->list('user-1');
        $this->assertCount(3, $list);

        foreach ($list as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('used', $item);
            $this->assertArrayNotHasKey('code_hash', $item);
        }
    }

    public function testListShowsUsedState(): void
    {
        $codes = $this->bc->generate('user-1', 2);
        $this->bc->consume('user-1', $codes[0]);

        $list = $this->bc->list('user-1');
        $usedItems = array_filter($list, static fn (array $i): bool => (int)$i['used'] === 1);
        $this->assertCount(1, $usedItems);
    }
}
