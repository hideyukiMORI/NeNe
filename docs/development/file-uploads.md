# File Uploads

How NeNe accepts `multipart/form-data` file uploads end-to-end: the framework helpers, storage convention, env-driven size limits, and the security details that surfaced during FT12 and FT46.

Audience: anyone authoring an endpoint that receives a file. Trial sources: FT12 (`docs/field-trials/2026-05-field-trial-12.md`), FT46 (`docs/field-trials/2026-05-field-trial-46.md`).

## The pieces

| What | Where |
| --- | --- |
| Typed wrapper for one `$_FILES` entry | `Nene\Xion\UploadedFile` |
| Fluent upload helper with direct HttpTermination | `Nene\Xion\FileUpload` |
| Read an uploaded file from the request | `Nene\Xion\Request::getFile(string $key): ?UploadedFile` |
| Validate (size, mime) — `UploadedFile` style | `UploadedFile::validate(['maxBytes' => N, 'allowedMime' => [...]])` |
| Validate (size, mime) — `FileUpload` style | `FileUpload::require()->validateSize(N)->validateMime([...])` |
| Move to a final destination | `UploadedFile::moveTo(string $target): bool` / `FileUpload::moveTo(string $dir): string` |
| Send a stored file back as a binary response | `ControllerBase::sendFile(string $path, string $mime, ?string $downloadName = null): never` |
| Suggested storage root | `data/uploads/` (mkdir'd by `init.sh`) |
| Upload size env overrides | `NENE_UPLOAD_MAX_FILESIZE`, `NENE_POST_MAX_SIZE` |
| Error codes | `UPLOAD-FILE-REQUIRED` (400), `UPLOAD-TOO-LARGE` (413), `UPLOAD-MIME-REJECTED` (415) |

## FileUpload — fluent multipart helper (FT46)

`FileUpload` is a standalone helper that loads, validates, and stores a single multipart file using a fluent chain. Validation failures throw `HttpTermination` directly (not `DomainException`), so the chain terminates immediately without reaching the top-level exception handler.

### API reference

| Method | Description |
| --- | --- |
| `FileUpload::require(string $field, ?array $files = null): self` | Load the field; throw 400 (`UPLOAD-FILE-REQUIRED`) if absent or errored. |
| `FileUpload::load(string $field, ?array $files = null): ?self` | Load the field; return `null` if absent (`UPLOAD_ERR_NO_FILE`). |
| `->validateSize(int $maxBytes): static` | Throw 413 (`UPLOAD-TOO-LARGE`) if `size > $maxBytes`. |
| `->validateMime(string[] $allowed): static` | Throw 415 (`UPLOAD-MIME-REJECTED`) if detected MIME is not in `$allowed`. |
| `->moveTo(string $destDir, ?string $filename = null): string` | Move to `$destDir`; generate random hex filename by default. Returns full destination path. |
| `->originalName(): string` | Client-supplied filename (basename only, path stripped). |
| `->tmpPath(): string` | PHP tmp path. |
| `->size(): int` | Reported file size in bytes. |
| `->mimeType(): string` | `finfo`-detected MIME type (client-supplied `$_FILES[]['type']` is never used). |

### Controller pattern

```php
class AvatarController extends ControllerBase
{
    public function indexPostRest(): array
    {
        $userId = $this->getLoginUserId();

        $upload = FileUpload::require('avatar')          // 400 if missing
            ->validateSize(2 * 1024 * 1024)              // 413 if > 2 MB
            ->validateMime(['image/jpeg', 'image/png']); // 415 if wrong type

        $dir = DIR_ROOT . 'data/uploads/' . $userId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create upload directory.');
        }

        $path = $upload->moveTo($dir); // random hex filename, original extension kept

        return $this->API_RESPONSE->success([
            'avatar' => [
                'path' => basename($path),
                'size' => $upload->size(),
                'mime' => $upload->mimeType(),
            ],
        ]);
    }
}
```

Each validation step returns `$this` for chaining. A failure throws `HttpTermination` with the appropriate status and catalog error code; the controller method never continues past the failed step.

### Destination directory setup

The destination directory must exist before calling `moveTo()`. Recommended practice:

- Store files **outside the webroot** (`data/uploads/` rather than `htdocs/uploads/`) so PHP cannot serve them as executable scripts even if a `.htaccess` guard is missing.
- Create per-user or per-entity sub-directories on demand (as shown above).
- Set ownership and permissions to `www-data:www-data` with mode `775` (matching `init.sh`'s conventions for `data/`).

### Generating safe filenames

`moveTo()` generates a `bin2hex(random_bytes(16))` filename by default, appending the original extension extracted from `originalName()`. This:

- Prevents collisions (128 bits of entropy).
- Avoids path-traversal attacks (the client filename is never used verbatim).
- Preserves the extension for readability without trusting the MIME the client claims.

Pass a `$filename` override only in tests or when the destination filename is determined by a preceding database insert.

## Receiving a file

```php
class AttachmentController extends ControllerBase
{
    public function indexPostRest(): array
    {
        $userId = $this->getLoginUserId();

        $file = $this->request->getFile('file')?->validate([
            'maxBytes'    => 1_000_000,
            'allowedMime' => ['image/png', 'image/jpeg', 'application/pdf'],
        ]);
        if ($file === null) {
            return $this->API_RESPONSE->failure('UPLOAD-FILE-REQUIRED');
        }

        $dir = DIR_ROOT . 'data/uploads/' . $userId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create upload directory.');
        }

        $ext  = match ($file->mime()) {
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            'application/pdf' => 'pdf',
        };
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!$file->moveTo($dir . '/' . $name)) {
            throw new \RuntimeException('Could not persist uploaded file.');
        }

        return $this->API_RESPONSE->success([
            'attachment' => [
                'id'   => $name,
                'size' => $file->size(),
                'mime' => $file->mime(),
            ],
        ]);
    }
}
```

`validate()` raises `DomainException` with the appropriate catalog code on failure; the top-level catch in `htdocs/index.php` turns that into the ADR-0003 JSON envelope automatically. Pre-validation, `getFile()` returns `null` when the form field is missing — surface that as `UPLOAD-FILE-REQUIRED` manually (the helper does not assume "missing field" means "client error" because some endpoints accept optional uploads).

The mime detection inside `UploadedFile` uses `finfo` on the moved tmp file. **The client-supplied `$_FILES['file']['type']` is ignored** — it is forgeable and must not be trusted.

## Returning a file

REST handlers normally return arrays (`ControllerBase::run()` JSON-encodes them). For binary downloads use `sendFile()`:

```php
public function itemGetRest(): array
{
    $userId = $this->getLoginUserId();
    $id     = (string)($this->request->getParam('id') ?? '');
    if (!preg_match('/^[a-f0-9]{16}\.(png|jpg|pdf)$/', $id)) {
        return $this->API_RESPONSE->failure('UPLOAD-FILE-REQUIRED');
    }

    $path = DIR_ROOT . 'data/uploads/' . $userId . '/' . $id;
    $mime = match (pathinfo($id, PATHINFO_EXTENSION)) {
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'pdf' => 'application/pdf',
    };

    $this->sendFile($path, $mime); // never returns — emits HttpTermination
}
```

`sendFile()` throws `HttpTermination` after building the response, so the `return $this->API_RESPONSE->...` line beneath it is unreachable (the function signature is annotated `never`). On a missing file it falls back to the framework's `notFound()` 404. Add `$downloadName` to switch from inline preview to `Content-Disposition: attachment` download.

## Storage convention

`init.sh` creates `data/uploads/` at every container start with `www-data:www-data` ownership and mode `775`. The framework does not enforce a sub-directory layout; the example above uses `data/uploads/<user_id>/<random>.<ext>` so files are scoped per user and collision-free. Other reasonable layouts:

- `data/uploads/<entity>/<id>.<ext>` — when the upload always attaches to a single entity row.
- `data/uploads/<yyyy>/<mm>/<random>.<ext>` — for date-bucketed retention.

`data/uploads/` is inside the project tree (and inside the Docker bind mount in dev). In production, mount a volume there or point a `NENE_UPLOAD_PATH` analogue at it (future work — see FT11 follow-ups for the path-override pattern with `NENE_LOG_PATH`).

## Size limits

PHP defaults are `upload_max_filesize = 2M` / `post_max_size = 8M`. A 3 MB file silently 413's *before* the controller sees the request, with response shape outside ADR-0003. Override via env:

```yaml
# compose.prod.yaml (or .env at the project root)
services:
  app:
    environment:
      NENE_UPLOAD_MAX_FILESIZE: "10M"
      NENE_POST_MAX_SIZE: "12M"
```

`init.sh` writes the values into `/usr/local/etc/php/conf.d/zz-nene-runtime.ini` on every container start. Constraint: `NENE_POST_MAX_SIZE` must be at least as large as `NENE_UPLOAD_MAX_FILESIZE`. See `docs/development/production-deployment.md` for the full env matrix.

The controller-level `maxBytes` constraint on `UploadedFile::validate()` is a **separate** check that runs *after* PHP has accepted the request — use both: PHP-level limit as the hard wall, controller-level limit for per-endpoint policy.

## Security checklist

`docs/review/file-upload.md` carries the full checklist. The four-line summary:

1. **`finfo` mime, not client mime.** `$_FILES[]['type']` is forgeable.
2. **Server-derived extension.** Pick the extension from the validated mime, not from `$_FILES[]['name']`. Reject `.php` / `.phtml` / `.html` / `.htaccess` even if the mime is plausible.
3. **Scope the storage path.** Include `<user_id>` (or another scope identifier) in the path so a future bug in id parsing cannot cross-pollinate two users' files.
4. **Bound the retrieve id.** Match `id_<X>` against an allowlist regex (`^[a-f0-9]{16}\.(png|jpg|pdf)$` in the example above) before concatenating it into a filesystem path.

The framework helper enforces #1 (`UploadedFile::mime()` uses `finfo`); the other three are the controller's responsibility because the right shape depends on the app.

## OpenAPI

Multipart endpoints document their request body as `multipart/form-data` with a `type: object` schema. The runtime contract test (`OpenApiRuntimeContractTest`) probes such operations with an empty body, so the documented `responses:` list must include the missing-file status (typically `400` `UPLOAD-FILE-REQUIRED`). Full example: `docs/tutorials/building-a-service.md` § "File upload (multipart) operations".

## Related

- `docs/development/error-codes.md` — `UPLOAD-*` catalog rows.
- `docs/development/production-deployment.md` — `NENE_UPLOAD_MAX_FILESIZE` / `NENE_POST_MAX_SIZE` env matrix.
- `docs/development/error-rendering.md` — how `DomainException('UPLOAD-...')` renders on REST vs HTML paths.
- `docs/review/file-upload.md` — review checklist.
- `docs/tutorials/building-a-service.md` § "Update OpenAPI" → "File upload (multipart) operations" — yaml shape.
- `docs/field-trials/2026-05-field-trial-12.md` — the trial that surfaced every behavior in the `UploadedFile` section.
- `docs/field-trials/2026-05-field-trial-46.md` — the trial that introduced the fluent `FileUpload` helper.
- `class/xion/UploadedFile.php` — original wrapper source.
- `class/xion/FileUpload.php` — fluent helper source (FT46).
- `class/xion/ControllerBase.php::sendFile` — binary response helper.
