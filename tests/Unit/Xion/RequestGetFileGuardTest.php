<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Request;
use PHPUnit\Framework\TestCase;

/**
 * Defense-in-depth checks for `Request::getFile()` (#408, eval report
 * PR #401 § 4): the helper must reject malformed `$_FILES` entries
 * before constructing an `UploadedFile`, and treat `UPLOAD_ERR_NO_FILE`
 * as "no file was uploaded" (return `null`) rather than handing the
 * controller an empty wrapper.
 */
final class RequestGetFileGuardTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalFiles;

    /** @var array<string,mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalFiles = $_FILES;
        $this->originalServer = $_SERVER;
        // Request's constructor walks the URL through UrlParameter, which
        // calls parse_url($_SERVER['REQUEST_URI']) — CLI mode leaves the
        // key unset, so stub it to a benign value the parser accepts.
        $_SERVER['REQUEST_URI'] = '/';
        if (!defined('LAYERS_NUM')) {
            define('LAYERS_NUM', 0);
        }
    }

    protected function tearDown(): void
    {
        $_FILES = $this->originalFiles;
        $_SERVER = $this->originalServer;
    }

    public function testReturnsNullWhenKeyAbsent(): void
    {
        $_FILES = [];
        $request = new Request();
        self::assertNull($request->getFile('upload'));
    }

    public function testReturnsNullWhenEntryNotAnArray(): void
    {
        $_FILES = ['upload' => 'not-an-array'];
        $request = new Request();
        self::assertNull($request->getFile('upload'));
    }

    public function testReturnsNullWhenCanonicalKeysMissing(): void
    {
        // Missing 'tmp_name' — the entry cannot describe a real upload.
        $_FILES = ['upload' => [
            'name' => 'a.txt',
            'type' => 'text/plain',
            'error' => UPLOAD_ERR_OK,
            'size' => 10,
        ]];
        $request = new Request();
        self::assertNull($request->getFile('upload'));
    }

    public function testReturnsNullForUploadErrNoFile(): void
    {
        // The browser submitted the form without selecting a file.
        $_FILES = ['upload' => [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]];
        $request = new Request();
        self::assertNull($request->getFile('upload'));
    }

    public function testReturnsUploadedFileForRealUpload(): void
    {
        $_FILES = ['upload' => [
            'name' => 'sample.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/php-upload-sample',
            'error' => UPLOAD_ERR_OK,
            'size' => 42,
        ]];
        $request = new Request();
        $file = $request->getFile('upload');
        self::assertNotNull($file);
        self::assertSame('sample.txt', $file->name());
        self::assertSame(42, $file->size());
    }

    public function testReturnsUploadedFileForOtherErrors(): void
    {
        // Errors that are not UPLOAD_ERR_NO_FILE still produce an
        // UploadedFile so the controller can surface the specific code
        // via UploadedFile::validate().
        $_FILES = ['upload' => [
            'name' => 'big.bin',
            'type' => 'application/octet-stream',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]];
        $request = new Request();
        $file = $request->getFile('upload');
        self::assertNotNull($file);
        self::assertFalse($file->isValid());
    }
}
