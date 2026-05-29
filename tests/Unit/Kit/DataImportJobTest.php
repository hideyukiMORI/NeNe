<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DataImportJob;
use PDO;
use PHPUnit\Framework\TestCase;

final class DataImportJobTest extends TestCase
{
    private PDO $pdo;
    private DataImportJob $imp;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE data_import_jobs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                filename     TEXT         NOT NULL,
                format       VARCHAR(20)  NOT NULL DEFAULT \'csv\',
                uploaded_by  VARCHAR(255) NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                total_rows   INTEGER      NULL,
                done_rows    INTEGER      NOT NULL DEFAULT 0,
                error_count  INTEGER      NOT NULL DEFAULT 0,
                started_at   DATETIME     NULL,
                finished_at  DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE data_import_errors (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id     INTEGER      NOT NULL,
                row_num    INTEGER      NOT NULL,
                field      VARCHAR(100) NULL,
                message    TEXT         NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->imp = new DataImportJob($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->imp->create('users.csv', 'csv', 'admin');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresCorrectly(): void
    {
        $id  = $this->imp->create('data.csv', 'csv', 'admin');
        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('data.csv', $row['filename']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['error_count']);
    }

    public function testCreateThrowsOnEmptyFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->imp->create('', 'csv', 'admin');
    }

    public function testCreateThrowsOnEmptyUploadedBy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->imp->create('file.csv', 'csv', '');
    }

    // ── startValidation ───────────────────────────────────────────────────────

    public function testStartValidationSetsStatus(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->assertTrue($this->imp->startValidation($id));

        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('validating', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['started_at']);
    }

    public function testStartValidationOnlyFromPending(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->startValidation($id);
        $this->assertFalse($this->imp->startValidation($id)); // already validating
    }

    // ── startProcessing ───────────────────────────────────────────────────────

    public function testStartProcessingSetsStatusAndTotal(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->startValidation($id);
        $this->assertTrue($this->imp->startProcessing($id, 100));

        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('processing', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, (int)$row['total_rows']);
    }

    // ── finish ────────────────────────────────────────────────────────────────

    public function testFinishSetsDoneStatus(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->startProcessing($id, 50);
        $this->assertTrue($this->imp->finish($id, 48));

        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('done', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(48, (int)$row['done_rows']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['finished_at']);
    }

    public function testFinishReturnsFalseWhenNotProcessing(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->assertFalse($this->imp->finish($id, 0)); // still pending
    }

    // ── fail ─────────────────────────────────────────────────────────────────

    public function testFailSetsFailedStatus(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->startValidation($id);
        $this->assertTrue($this->imp->fail($id, 'File format error'));

        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $row['status']);
    }

    public function testFailAddsReasonAsError(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->fail($id, 'Reason text');

        $errors = $this->imp->errors($id);
        $this->assertCount(1, $errors);
        $this->assertSame('Reason text', $errors[0]['message']);
    }

    public function testFailReturnsFalseIfAlreadyDone(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->startProcessing($id, 10);
        $this->imp->finish($id, 10);
        $this->assertFalse($this->imp->fail($id));
    }

    // ── addError / errors ─────────────────────────────────────────────────────

    public function testAddErrorStoresError(): void
    {
        $id  = $this->imp->create('f.csv', 'csv', 'u1');
        $eid = $this->imp->addError($id, 5, 'email', 'Invalid format');
        $this->assertGreaterThan(0, $eid);

        $errors = $this->imp->errors($id);
        $this->assertCount(1, $errors);
        $this->assertSame(5, (int)$errors[0]['row_num']);
        $this->assertSame('email', $errors[0]['field']);
        $this->assertSame('Invalid format', $errors[0]['message']);
    }

    public function testAddErrorIncrementsErrorCount(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->addError($id, 1, null, 'E1');
        $this->imp->addError($id, 2, null, 'E2');

        $row = $this->imp->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['error_count']);
    }

    public function testErrorsOrderedByRowNum(): void
    {
        $id = $this->imp->create('f.csv', 'csv', 'u1');
        $this->imp->addError($id, 10, null, 'E10');
        $this->imp->addError($id, 3, null, 'E3');
        $this->imp->addError($id, 7, null, 'E7');

        $errors = $this->imp->errors($id);
        $this->assertSame(3, (int)$errors[0]['row_num']);
        $this->assertSame(7, (int)$errors[1]['row_num']);
        $this->assertSame(10, (int)$errors[2]['row_num']);
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsUsersJobs(): void
    {
        $this->imp->create('a.csv', 'csv', 'u1');
        $this->imp->create('b.csv', 'csv', 'u1');
        $this->imp->create('c.csv', 'csv', 'u2');

        $jobs = $this->imp->forUser('u1');
        $this->assertCount(2, $jobs);
    }

    public function testForUserReturnsNewestFirst(): void
    {
        $id1 = $this->imp->create('a.csv', 'csv', 'u1');
        $id2 = $this->imp->create('b.csv', 'csv', 'u1');

        $jobs = $this->imp->forUser('u1');
        $this->assertSame($id2, (int)$jobs[0]['id']);
        $this->assertSame($id1, (int)$jobs[1]['id']);
    }
}
