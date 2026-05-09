<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionManagerTest extends TestCase
{
    private TransactionRecordingPdo $pdo;

    protected function setUp(): void
    {
        $this->pdo = new TransactionRecordingPdo();
    }

    public function testRunCommitsSuccessfulCallbackAndReturnsValue(): void
    {
        $result = (new TransactionManager($this->pdo))->run(function (): string {
            $this->pdo->recordWrite('committed');
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['committed'], $this->pdo->committedWrites());
        self::assertSame(1, $this->pdo->beginCount);
        self::assertSame(1, $this->pdo->commitCount);
        self::assertSame(0, $this->pdo->rollbackCount);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testRunRollsBackWhenCallbackThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed');

        try {
            (new TransactionManager($this->pdo))->run(function (): void {
                $this->pdo->recordWrite('rolled-back');
                throw new RuntimeException('failed');
            });
        } finally {
            self::assertSame([], $this->pdo->committedWrites());
            self::assertSame(1, $this->pdo->beginCount);
            self::assertSame(0, $this->pdo->commitCount);
            self::assertSame(1, $this->pdo->rollbackCount);
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testRunDoesNotCommitOuterTransaction(): void
    {
        $this->pdo->beginTransaction();

        (new TransactionManager($this->pdo))->run(function (): void {
            $this->pdo->recordWrite('outer-owned');
        });

        self::assertTrue($this->pdo->inTransaction());
        self::assertSame([], $this->pdo->committedWrites());
        self::assertSame(1, $this->pdo->beginCount);
        self::assertSame(0, $this->pdo->commitCount);
        self::assertSame(0, $this->pdo->rollbackCount);

        $this->pdo->rollBack();

        self::assertSame([], $this->pdo->committedWrites());
        self::assertFalse($this->pdo->inTransaction());
    }
}

final class TransactionRecordingPdo extends PDO
{
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    /**
     * @var array<int,string>
     */
    private array $committedWrites = [];

    /**
     * @var array<int,string>
     */
    private array $pendingWrites = [];

    private bool $inTransaction = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->beginCount++;
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->commitCount++;
        $this->committedWrites = array_merge($this->committedWrites, $this->pendingWrites);
        $this->pendingWrites = [];
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCount++;
        $this->pendingWrites = [];
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function recordWrite(string $value): void
    {
        if ($this->inTransaction) {
            $this->pendingWrites[] = $value;
            return;
        }
        $this->committedWrites[] = $value;
    }

    /**
     * @return array<int,string>
     */
    public function committedWrites(): array
    {
        return $this->committedWrites;
    }
}
