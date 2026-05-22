<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DomainException;
use Nene\Xion\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * UploadedFile validation logic. The PHP `is_uploaded_file()` check that
 * `isValid()` performs requires an actual SAPI upload, so tests that
 * exercise it use a per-test tmp file plus mock-friendly inputs.
 *
 * `validate()` short-circuits on `isValid()`, so any tmp_name that fails
 * `is_uploaded_file()` raises `UPLOAD-FILE-REQUIRED` before size or mime
 * checks run. The tests use real tmp files for *size* / *mime* coverage
 * by stubbing `isValid()` via a small subclass.
 */
final class UploadedFileTest extends TestCase
{
    public function testAccessorsReflectUploadArray(): void
    {
        $file = new UploadedFile([
            'name' => 'foo.png',
            'size' => 1234,
            'tmp_name' => '/tmp/nonexistent-' . bin2hex(random_bytes(4)),
            'error' => UPLOAD_ERR_OK,
        ]);

        self::assertSame('foo.png', $file->name());
        self::assertSame(1234, $file->size());
        self::assertStringStartsWith('/tmp/', $file->tmpPath());
    }

    public function testIsValidReturnsFalseForFakeTmpPath(): void
    {
        $file = new UploadedFile([
            'name' => 'foo.png',
            'size' => 10,
            'tmp_name' => '/tmp/nonexistent-' . bin2hex(random_bytes(4)),
            'error' => UPLOAD_ERR_OK,
        ]);

        self::assertFalse($file->isValid());
    }

    public function testIsValidReturnsFalseWhenPhpReportsError(): void
    {
        $file = new UploadedFile([
            'name' => 'foo.png',
            'size' => 0,
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        self::assertFalse($file->isValid());
    }

    public function testValidateThrowsFileRequiredWhenInvalid(): void
    {
        $file = new UploadedFile([
            'name' => 'foo.png',
            'size' => 10,
            'tmp_name' => '/tmp/nonexistent-' . bin2hex(random_bytes(4)),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('UPLOAD-FILE-REQUIRED');
        $file->validate();
    }

    public function testValidateThrowsTooLargeWhenOverMaxBytes(): void
    {
        $file = new UploadedFileWithForcedValid([
            'name' => 'big.bin',
            'size' => 5000,
            'tmp_name' => '/tmp/nonexistent',
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('UPLOAD-TOO-LARGE');
        $file->validate(['maxBytes' => 1000]);
    }

    public function testValidateAcceptsWhenWithinMaxBytes(): void
    {
        $file = new UploadedFileWithForcedValid([
            'name' => 'small.bin',
            'size' => 500,
            'tmp_name' => '/tmp/nonexistent',
            'error' => UPLOAD_ERR_OK,
        ]);
        $file->setMime('application/octet-stream');

        $returned = $file->validate([
            'maxBytes' => 1000,
            'allowedMime' => ['application/octet-stream'],
        ]);

        self::assertSame($file, $returned);
    }

    public function testValidateRejectsMimeNotOnAllowlist(): void
    {
        $file = new UploadedFileWithForcedValid([
            'name' => 'bad.bin',
            'size' => 100,
            'tmp_name' => '/tmp/nonexistent',
            'error' => UPLOAD_ERR_OK,
        ]);
        $file->setMime('application/octet-stream');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('UPLOAD-MIME-REJECTED');
        $file->validate(['allowedMime' => ['image/png']]);
    }
}

/**
 * Test-only subclass that forces `isValid()` to return true (so we can
 * exercise the size / mime branches without a real SAPI upload) and lets
 * the test set the detected mime instead of calling `finfo`.
 */
class UploadedFileWithForcedValid extends UploadedFile
{
    private string $forcedMime = 'application/octet-stream';

    public function isValid(): bool
    {
        return true;
    }

    public function setMime(string $mime): void
    {
        $this->forcedMime = $mime;
    }

    public function mime(): string
    {
        return $this->forcedMime;
    }
}
