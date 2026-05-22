<?php

declare(strict_types=1);

namespace Nene\Xion;

use finfo;

/**
 * Typed wrapper around a single `$_FILES` entry.
 *
 * Built by {@see Request::getFile()}. Carries the values the framework
 * trusts (client-supplied original name, server-measured size, tmp path)
 * plus a lazy `finfo` mime detection that ignores the client-supplied
 * `type` (which is forgeable). FT12 F-1 / F-3.
 */
class UploadedFile
{
    private string $name;
    private int $size;
    private string $tmpPath;
    private int $error;
    private ?string $mime = null;

    /**
     * @param array{name:string,size:int,tmp_name:string,error:int} $upload
     */
    public function __construct(array $upload)
    {
        $this->name = (string)($upload['name'] ?? '');
        $this->size = (int)($upload['size'] ?? 0);
        $this->tmpPath = (string)($upload['tmp_name'] ?? '');
        $this->error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    /**
     * Detect the mime type via `finfo` from the moved-tmp file, **not** the
     * client-supplied `type` (which is forgeable). Cached after the first call.
     */
    public function mime(): string
    {
        if ($this->mime === null) {
            $this->mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($this->tmpPath);
        }
        return $this->mime;
    }

    /**
     * Whether PHP accepted the upload and the tmp file is real.
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->tmpPath !== '' && is_uploaded_file($this->tmpPath);
    }

    /**
     * Validate against framework-supplied constraints and return `$this`,
     * or throw {@see DomainException} with a catalog error code.
     *
     * Constraints:
     *
     * - `maxBytes` (int)              — reject when `size() > maxBytes`        → `UPLOAD-TOO-LARGE`
     * - `allowedMime` (array<string>) — `finfo` mime must be in this list     → `UPLOAD-MIME-REJECTED`
     *
     * `isValid()` is always checked first; failure raises `UPLOAD-FILE-REQUIRED`.
     *
     * @param array{maxBytes?:int, allowedMime?:array<int,string>} $constraints
     */
    public function validate(array $constraints = []): self
    {
        if (!$this->isValid()) {
            throw new DomainException('UPLOAD-FILE-REQUIRED');
        }
        if (isset($constraints['maxBytes']) && $this->size > (int)$constraints['maxBytes']) {
            throw new DomainException('UPLOAD-TOO-LARGE');
        }
        if (isset($constraints['allowedMime']) && !in_array($this->mime(), $constraints['allowedMime'], true)) {
            throw new DomainException('UPLOAD-MIME-REJECTED');
        }
        return $this;
    }

    /**
     * Move the uploaded tmp file to a target path. Wraps PHP's
     * `move_uploaded_file()` and re-checks `is_uploaded_file()` defensively.
     */
    public function moveTo(string $target): bool
    {
        if (!is_uploaded_file($this->tmpPath)) {
            return false;
        }
        return move_uploaded_file($this->tmpPath, $target);
    }
}
