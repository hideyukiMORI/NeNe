<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\FileUpload;
use Nene\Xion\HttpTermination;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * FileUpload validation and factory logic.
 *
 * Tests use a real temp file with a minimal JPEG header so that finfo
 * detects the mime type without a live HTTP upload. Static factory
 * methods accept an injectable $files array to avoid touching $_FILES.
 */
final class FileUploadTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        if (!defined('ERROR_CODE_PATH')) {
            define('ERROR_CODE_PATH', dirname(__DIR__, 3) . '/config/error_codes.php');
        }
        // Write a minimal JPEG SOI + APP0 header so finfo detects image/jpeg.
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($this->tmpFile, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 16));
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && file_exists($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    // -----------------------------------------------------------------------
    // load() — returns null when field is absent or UPLOAD_ERR_NO_FILE
    // -----------------------------------------------------------------------

    public function testLoadReturnsNullWhenFieldAbsent(): void
    {
        $result = FileUpload::load('photo', []);
        self::assertNull($result);
    }

    public function testLoadReturnsNullWhenErrorIsNoFile(): void
    {
        $files = [
            'photo' => [
                'name'     => '',
                'tmp_name' => '',
                'size'     => 0,
                'error'    => UPLOAD_ERR_NO_FILE,
                'type'     => '',
            ],
        ];
        $result = FileUpload::load('photo', $files);
        self::assertNull($result);
    }

    public function testLoadReturnsInstanceForValidUpload(): void
    {
        $files = $this->fakeFiles('test.jpg');
        $upload = FileUpload::load('photo', $files);
        self::assertInstanceOf(FileUpload::class, $upload);
    }

    // -----------------------------------------------------------------------
    // require() — throws HttpTermination when field is absent or errored
    // -----------------------------------------------------------------------

    public function testRequireThrowsWhenFieldAbsent(): void
    {
        $this->expectException(HttpTermination::class);
        FileUpload::require('photo', []);
    }

    public function testRequireThrowsWhenErrorCode(): void
    {
        $files = [
            'photo' => [
                'name'     => 'big.jpg',
                'tmp_name' => $this->tmpFile,
                'size'     => filesize($this->tmpFile),
                'error'    => UPLOAD_ERR_INI_SIZE,
                'type'     => 'image/jpeg',
            ],
        ];
        try {
            FileUpload::require('photo', $files);
            self::fail('Expected HttpTermination');
        } catch (Throwable $e) {
            self::assertInstanceOf(HttpTermination::class, $e);
            self::assertSame(400, $e->response()->statusCode());
        }
    }

    // -----------------------------------------------------------------------
    // validateSize()
    // -----------------------------------------------------------------------

    public function testValidateSizePassesWhenWithinLimit(): void
    {
        $files  = $this->fakeFiles('test.jpg');
        $upload = FileUpload::require('photo', $files);
        $result = $upload->validateSize(1_000_000);
        self::assertSame($upload, $result);
    }

    public function testValidateSizeThrowsWhenExceedsLimit(): void
    {
        $files  = $this->fakeFiles('test.jpg');
        $upload = FileUpload::require('photo', $files);
        try {
            $upload->validateSize(1); // tmpFile is > 1 byte
            self::fail('Expected HttpTermination');
        } catch (Throwable $e) {
            self::assertInstanceOf(HttpTermination::class, $e);
            self::assertSame(413, $e->response()->statusCode());
        }
    }

    // -----------------------------------------------------------------------
    // validateMime()
    // -----------------------------------------------------------------------

    public function testValidateMimePassesForAllowedType(): void
    {
        $files  = $this->fakeFiles('test.jpg');
        $upload = FileUpload::require('photo', $files);
        $result = $upload->validateMime(['image/jpeg', 'image/png']);
        self::assertSame($upload, $result);
    }

    public function testValidateMimeThrowsForRejectedType(): void
    {
        $files  = $this->fakeFiles('test.jpg');
        $upload = FileUpload::require('photo', $files);
        try {
            $upload->validateMime(['image/png', 'image/gif']);
            self::fail('Expected HttpTermination');
        } catch (Throwable $e) {
            self::assertInstanceOf(HttpTermination::class, $e);
            self::assertSame(415, $e->response()->statusCode());
        }
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    public function testAccessorsReturnConstructedValues(): void
    {
        $files  = $this->fakeFiles('avatar.jpg');
        $upload = FileUpload::require('photo', $files);

        self::assertSame('avatar.jpg', $upload->originalName());
        self::assertSame($this->tmpFile, $upload->tmpPath());
        self::assertGreaterThan(0, $upload->size());
        self::assertSame('image/jpeg', $upload->mimeType());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a fake $_FILES-style array pointing at the real tmp file.
     *
     * @return array<string, array<string,mixed>>
     */
    private function fakeFiles(string $name): array
    {
        return [
            'photo' => [
                'name'     => $name,
                'tmp_name' => $this->tmpFile,
                'size'     => (int) filesize($this->tmpFile),
                'error'    => UPLOAD_ERR_OK,
                'type'     => 'image/jpeg',
            ],
        ];
    }
}
