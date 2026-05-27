<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Safe wrapper for a single uploaded file ($_FILES entry).
 *
 * Validates MIME type and size, then moves the file to a destination
 * directory. Throws HttpTermination on validation failure so controllers
 * stay thin.
 *
 * Usage in a controller:
 *
 *   $upload = FileUpload::require('avatar');           // 400 if missing
 *   $upload->validateSize(2 * 1024 * 1024)            // 413 if > 2 MB
 *           ->validateMime(['image/jpeg', 'image/png']); // 415 if wrong type
 *   $filename = $upload->moveTo('/var/uploads/avatars');
 */
final class FileUpload
{
    private function __construct(
        private readonly string $originalName,
        private readonly string $tmpPath,
        private readonly int    $size,
        private readonly string $mimeType,
    ) {}

    /**
     * Load an uploaded file from $_FILES, throwing 400 if absent or invalid.
     *
     * @param string $field   The $_FILES key.
     * @param array<string,mixed>|null $files Injectable for tests (defaults to $_FILES).
     * @throws HttpTermination 400 if the field is missing or upload failed.
     */
    public static function require(string $field, ?array $files = null): self
    {
        $files ??= $_FILES;

        if (!isset($files[$field]) || ($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new HttpTermination(
                JsonResponder::responseArray((new ApiResponse())->failure('UPLOAD-FILE-REQUIRED'))
            );
        }

        $entry = $files[$field];
        $error = (int) ($entry['error'] ?? UPLOAD_ERR_OK);

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpTermination(
                JsonResponder::responseArray((new ApiResponse())->failure('UPLOAD-FILE-REQUIRED'))
            );
        }

        $tmpPath = (string) ($entry['tmp_name'] ?? '');
        // Detect MIME from the actual file bytes (not the client-supplied value)
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if ($mimeType === false) {
            $mimeType = (string) ($entry['type'] ?? 'application/octet-stream');
        }

        return new self(
            originalName: basename((string) ($entry['name'] ?? '')),
            tmpPath:      $tmpPath,
            size:         (int) ($entry['size'] ?? 0),
            mimeType:     $mimeType,
        );
    }

    /**
     * Load an uploaded file, returning null if absent.
     *
     * @param string $field
     * @param array<string,mixed>|null $files Injectable for tests.
     */
    public static function load(string $field, ?array $files = null): ?self
    {
        $files ??= $_FILES;
        if (!isset($files[$field]) || ($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return self::require($field, $files);
    }

    /**
     * Validate the file size; throw 413 if exceeded.
     *
     * @param int $maxBytes Maximum allowed size in bytes.
     * @return $this For fluent chaining.
     */
    public function validateSize(int $maxBytes): static
    {
        if ($this->size > $maxBytes) {
            throw new HttpTermination(
                JsonResponder::responseArray((new ApiResponse())->failure('UPLOAD-TOO-LARGE'))
            );
        }
        return $this;
    }

    /**
     * Validate the detected MIME type; throw 415 if not allowed.
     *
     * @param string[] $allowed Permitted MIME types (e.g. ['image/jpeg', 'image/png']).
     * @return $this For fluent chaining.
     */
    public function validateMime(array $allowed): static
    {
        if (!in_array($this->mimeType, $allowed, true)) {
            throw new HttpTermination(
                JsonResponder::responseArray((new ApiResponse())->failure('UPLOAD-MIME-REJECTED'))
            );
        }
        return $this;
    }

    /**
     * Move the uploaded file to a destination directory.
     *
     * Generates a random filename to prevent collisions and path traversal.
     * The original extension is preserved.
     *
     * @param string      $destDir  Destination directory (must exist).
     * @param string|null $filename Override the generated filename (useful for tests).
     * @return string The full path to the moved file.
     */
    public function moveTo(string $destDir, ?string $filename = null): string
    {
        if ($filename === null) {
            $ext      = pathinfo($this->originalName, PATHINFO_EXTENSION);
            $filename = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        }
        $destPath = rtrim($destDir, '/') . '/' . $filename;
        // In a real request, use move_uploaded_file(). In tests, rename() is used.
        if (is_uploaded_file($this->tmpPath)) {
            move_uploaded_file($this->tmpPath, $destPath);
        } else {
            rename($this->tmpPath, $destPath);
        }
        return $destPath;
    }

    public function originalName(): string { return $this->originalName; }
    public function tmpPath(): string      { return $this->tmpPath; }
    public function size(): int            { return $this->size; }
    public function mimeType(): string     { return $this->mimeType; }
}
