<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Monolog\Logger;
use Nene\Xion\AuditLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditLogger (FT33 — audit trail).
 *
 * Strategy: AuditLogger accepts PDO and Logger via constructor injection.
 * A mock PDO and a no-handler Logger are passed directly, so no real
 * database or container is needed.
 *
 * Coverage:
 *  - record() executes INSERT with correct bound parameters
 *  - record() JSON-encodes the payload
 *  - record() JSON-encodes Japanese characters with JSON_UNESCAPED_UNICODE
 *  - record() does not propagate PDOException from execute() (catches internally)
 *  - record() does not propagate PDOException from prepare() (catches internally)
 *  - default table name is 'audit_log'
 *  - custom table name is used in SQL
 */
final class AuditLoggerTest extends TestCase
{
    /** @var PDO&MockObject */
    private PDO $mockPdo;

    /** @var PDOStatement&MockObject */
    private PDOStatement $mockStmt;

    private Logger $nullLogger;

    private AuditLogger $logger;

    protected function setUp(): void
    {
        $this->mockPdo    = $this->createMock(PDO::class);
        $this->mockStmt   = $this->createMock(PDOStatement::class);
        // No-handler logger: error() calls do not fail, nothing is written.
        $this->nullLogger = new Logger('test');
        $this->logger     = new AuditLogger('audit_log', $this->mockPdo, $this->nullLogger);
    }

    // ------------------------------------------------------------------ //
    // record() — INSERT execution
    // ------------------------------------------------------------------ //

    public function testRecordExecutesInsert(): void
    {
        $this->mockPdo
            ->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())->method('execute');

        $this->logger->record(1, 'created', 'task', 42, ['title' => 'hello']);
    }

    public function testRecordSqlContainsExpectedTokens(): void
    {
        $capturedSql = '';

        $this->mockPdo
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): PDOStatement {
                $capturedSql = $sql;
                return $this->mockStmt;
            });

        $this->mockStmt->method('execute');

        $this->logger->record(7, 'updated', 'order', 99, []);

        $this->assertStringContainsString('INSERT INTO', $capturedSql);
        $this->assertStringContainsString('audit_log', $capturedSql);
        $this->assertStringContainsString(':actor_id', $capturedSql);
        $this->assertStringContainsString(':action', $capturedSql);
        $this->assertStringContainsString(':resource_type', $capturedSql);
        $this->assertStringContainsString(':resource_id', $capturedSql);
        $this->assertStringContainsString(':payload', $capturedSql);
    }

    public function testRecordBindsCorrectParameters(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);

        $capture = new BindingCapture();

        $this->mockStmt
            ->method('bindValue')
            ->willReturnCallback(function (string $param, mixed $value, int $type) use ($capture): bool {
                $capture->add($param, $value, $type);
                return true;
            });

        $this->mockStmt->method('execute');

        $this->logger->record(5, 'deleted', 'user', 123, []);

        $this->assertSame(5, $capture->value(':actor_id'));
        $this->assertSame(PDO::PARAM_INT, $capture->type(':actor_id'));

        $this->assertSame('deleted', $capture->value(':action'));
        $this->assertSame(PDO::PARAM_STR, $capture->type(':action'));

        $this->assertSame('user', $capture->value(':resource_type'));
        $this->assertSame(PDO::PARAM_STR, $capture->type(':resource_type'));

        $this->assertSame(123, $capture->value(':resource_id'));
        $this->assertSame(PDO::PARAM_INT, $capture->type(':resource_id'));
    }

    // ------------------------------------------------------------------ //
    // record() — JSON payload encoding
    // ------------------------------------------------------------------ //

    public function testRecordEncodesPayloadAsJson(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);

        $capturedPayload = '';

        $this->mockStmt
            ->method('bindValue')
            ->willReturnCallback(function (string $param, mixed $value) use (&$capturedPayload): bool {
                if ($param === ':payload') {
                    $capturedPayload = (string)$value;
                }
                return true;
            });

        $this->mockStmt->method('execute');

        $this->logger->record(1, 'created', 'task', 10, ['title' => 'buy milk', 'done' => false]);

        $decoded = json_decode($capturedPayload, true);
        $this->assertSame(['title' => 'buy milk', 'done' => false], $decoded);
    }

    public function testRecordEncodesJapanesePayloadWithoutEscaping(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);

        $capturedPayload = '';

        $this->mockStmt
            ->method('bindValue')
            ->willReturnCallback(function (string $param, mixed $value) use (&$capturedPayload): bool {
                if ($param === ':payload') {
                    $capturedPayload = (string)$value;
                }
                return true;
            });

        $this->mockStmt->method('execute');

        $this->logger->record(1, 'created', 'task', 1, ['title' => '日本語タイトル']);

        // JSON_UNESCAPED_UNICODE keeps multibyte chars as-is (not \uXXXX).
        $this->assertStringContainsString('日本語タイトル', $capturedPayload);
    }

    public function testRecordEncodesEmptyPayloadAsEmptyJsonArray(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);

        $capturedPayload = '';

        $this->mockStmt
            ->method('bindValue')
            ->willReturnCallback(function (string $param, mixed $value) use (&$capturedPayload): bool {
                if ($param === ':payload') {
                    $capturedPayload = (string)$value;
                }
                return true;
            });

        $this->mockStmt->method('execute');

        $this->logger->record(1, 'created', 'task', 1, []);

        $this->assertSame('[]', $capturedPayload);
    }

    // ------------------------------------------------------------------ //
    // record() — PDOException swallowed
    // ------------------------------------------------------------------ //

    public function testRecordDoesNotPropagateExecutePdoException(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);

        $this->mockStmt->method('bindValue')->willReturn(true);
        $this->mockStmt
            ->method('execute')
            ->willThrowException(new \PDOException('Disk full'));

        // record() must catch the exception internally — no exception surfaces here.
        $this->logger->record(1, 'created', 'task', 1, []);

        // Reaching this assertion confirms the exception was swallowed.
        $this->addToAssertionCount(1);
    }

    public function testRecordDoesNotPropagatePrepareException(): void
    {
        $this->mockPdo
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection lost'));

        // prepare() throws PDOException; record() catches it internally.
        $this->logger->record(2, 'deleted', 'order', 7, []);

        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------ //
    // Custom table name
    // ------------------------------------------------------------------ //

    public function testCustomTableNameAppearsInSql(): void
    {
        $customLogger = new AuditLogger('tenant_audit_log', $this->mockPdo, $this->nullLogger);

        $capturedSql = '';

        $this->mockPdo
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): PDOStatement {
                $capturedSql = $sql;
                return $this->mockStmt;
            });

        $this->mockStmt->method('execute');

        $customLogger->record(1, 'created', 'task', 1, []);

        $this->assertStringContainsString('tenant_audit_log', $capturedSql);
    }
}

// ======================================================================
// Test helpers
// ======================================================================

/**
 * Captures bindValue() calls so Phan can type-check the assertions
 * without encountering array-offset issues from closures with &$bindings.
 */
final class BindingCapture
{
    /** @var array<string, array{value: mixed, type: int}> */
    private array $data = [];

    public function add(string $param, mixed $value, int $type): void
    {
        $this->data[$param] = ['value' => $value, 'type' => $type];
    }

    public function value(string $param): mixed
    {
        return $this->data[$param]['value'];
    }

    public function type(string $param): int
    {
        return $this->data[$param]['type'];
    }
}
