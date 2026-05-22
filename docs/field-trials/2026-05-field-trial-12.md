# Field Trial 12 — file-upload (multipart/form-data: Request helpers, binary response, OpenAPI, security)

Methodology reference: `docs/field-trials/README.md`. Report skeleton: `docs/templates/field-trial-report.md`. Trial Issue: #360.

## Date

2026-05-22

## Baseline

- NeNe ref: post-FT11 main (ADR-0005 + SchemaCompiler shipped, error-code drift gate live).
- Clone path: `/home/xi/github/NeNe-FT/ft12-file-upload/`
- Host ports: app=8092, mysql=3319
- PHP: 8.4.21 (in `php:8.4-apache` container)
- Database: MySQL 8.4

### Baseline verification

| Check | Result |
| --- | --- |
| `docker compose run --rm app composer install` | 58 packages, lock-pinned |
| `docker compose up -d app` + `GET /health` | HTTP 200, `healthStatus=ok` |
| `composer test` | 57 / 57 (FT11 leaves us at 57: 51 + 6 SchemaCompiler) |
| `composer test:http` | 23 / 23, 219 assertions, 1 expected skip |

## Goal

FT1–FT11 did not exercise file uploads. `class/xion/Request.php` exposes `getPost` / `getQuery` / `getParam` only — there is no `$_FILES` accessor, no upload helper, no docs. Real-world apps need at least one of: avatar / image upload, CSV import, PDF attachment. FT12 walks the smallest viable upload through the documented workflow, observes the friction at each step, and records it.

The trial is end-to-end-observational. The aim is to surface "what is missing if a contributor tries to ship a file upload tomorrow," not to ship the helper itself.

## Service Built

- Name: `AttachmentController` (probe, non-committed) with `POST /attachment/index` and `GET /attachment/item/id_{id}`.
- Storage: `data/uploads/<user_id>/<random>.<ext>` (per-user, scoped, runtime-mkdir).
- Validation: `is_uploaded_file` + size cap (1 MB) + `finfo` mime allowlist (`image/png`, `image/jpeg`, `application/pdf`) + extension allowlist via regex on the retrieve id.
- Error codes added (in the clone, also added to `docs/development/error-codes.md` to satisfy the FT10 #352 drift test): `ATTACHMENT-FILE-REQUIRED`, `ATTACHMENT-TOO-LARGE`, `ATTACHMENT-MIME-REJECTED`, `ATTACHMENT-ID-REQUIRED`, `ATTACHMENT-NOT-FOUND`.

Probe files stay in the clone; not committed back.

## Steps Taken

### 1. Cold survey

`grep -rE '\$_FILES|move_uploaded_file|multipart|getFile' --include="*.php"` returned **zero** matches across the whole repo. `Request.php` has `getPost` / `getQuery` / `getParam` only. `docs/` has two unrelated "upload" hits (a Zenn article image URL and `.htaccess upload` in the deployment doc). No code, no docs, no convention.

### 2. Writing the probe — F-1, F-3

`AttachmentController::indexPostRest()` had to reach into `$_FILES` directly because `Request` has no accessor:

```php
$upload = $_FILES['file'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return $this->API_RESPONSE->failure('ATTACHMENT-FILE-REQUIRED');
}
if (!is_uploaded_file((string)$upload['tmp_name'])) {
    return $this->API_RESPONSE->failure('ATTACHMENT-FILE-REQUIRED');
}
if ((int)$upload['size'] > self::MAX_BYTES) {
    return $this->API_RESPONSE->failure('ATTACHMENT-TOO-LARGE');
}
$mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
if (!in_array($mime, self::ALLOWED_MIME, true)) {
    return $this->API_RESPONSE->failure('ATTACHMENT-MIME-REJECTED');
}
```

Five inline checks, easy to forget one.

**Finding (F-1)**: `Request` should expose `getFile()` / a typed `UploadedFile` so controllers don't import the super-global.

**Finding (F-3)**: A validation helper that bundles `is_uploaded_file` + size cap + `finfo` mime check would prevent the "ship a bypass by skipping one check" footgun.

### 3. Storing the file — F-4

`init.sh` mkdir's `data/` but not `data/uploads/`. The probe `mkdir`s `data/uploads/<user_id>` at runtime, which works (parent is www-data-writable), but the convention is implicit. A reader of `init.sh` would not know `data/uploads/` is the convention; a reader of `Request.php` finds nothing.

**Finding (F-4)**: Decide whether `data/uploads/` is the convention. If yes, mkdir in `init.sh` alongside `data/`, `view/compile/`, `view/plugins/`, `log/`.

### 4. PHP ini defaults — F-5

`docker compose exec app php -i | grep -i upload` returned the stock PHP defaults:

```
upload_max_filesize => 2M
post_max_size => 8M
file_uploads => On
max_file_uploads => 20
```

A 3 MB PDF upload would fail before the controller sees the request, with response shape outside ADR-0003. No `NENE_UPLOAD_MAX_FILESIZE` env var exists. `docker/php/conf.d/zz-nene.ini` (added by FT8 #329 for `expose_php = Off`) is the natural drop-in spot.

**Finding (F-5)**: Add `NENE_UPLOAD_MAX_FILESIZE` / `NENE_POST_MAX_SIZE` env overrides via the existing PHP conf.d drop-in. Document that `post_max_size ≥ upload_max_filesize`.

### 5. Returning the file — F-2

`ControllerBase::run()` JSON-encodes every `*Rest` handler's return value. There is no way to return a binary from a REST handler. The probe's `itemGetRest` therefore base64-encodes the file body into the JSON envelope:

```php
return $this->API_RESPONSE->success([
    'attachment' => [
        'id' => $id,
        'size' => filesize($path),
        'sha256' => hash_file('sha256', $path),
        'base64' => base64_encode((string)file_get_contents($path)),
    ],
]);
```

For a 1×1 PNG used in the live test that's fine; for a 5 MB PDF served inline to a browser it's wrong. Three viable shapes:

- (a) Let `*Rest` handlers return an `HttpResponse` and have `run()` emit it directly.
- (b) Introduce `*Binary` / `*File` handler suffix.
- (c) Provide `ControllerBase::sendFile(string $path, string $mime): never` that throws `HttpTermination`.

Each is small. The trade-off is contract-test interaction (the contract test currently assumes REST returns JSON).

**Finding (F-2)**: Pick one of the three shapes and ship a documented "REST handler returns a binary" path.

### 6. OpenAPI authoring — F-6

`docs/api/openapi.yaml` only documents `application/json` request bodies. OpenAPI 3.1 documents multipart as:

```yaml
requestBody:
  content:
    multipart/form-data:
      schema:
        type: object
        properties:
          file:
            type: string
            format: binary
```

Neither `docs/review/openapi-contract.md` nor `docs/tutorials/building-a-service.md` covers this. `tests/Http/OpenApiRuntimeContractTest.php` auto-probes operations using the first example under `application/json` — what it does for a multipart operation (skip? crash? probe with empty body?) is not documented and was not exercised by this trial.

**Finding (F-6)**: Document multipart authoring in the review checklist + tutorial. Verify the contract test handles a multipart operation gracefully or annotate it as `x-nene-runtime-probe: skip` (existing opt-out hook).

### 7. HTML tutorial — F-7

`<form enctype="multipart/form-data">` is not mentioned anywhere in `docs/tutorials/building-a-service.md`. F-1 / F-3 will surface again on any HTML form upload page (avatar, CSV import).

**Finding (F-7)**: Add a "Accept a file upload" subsection to the tutorial after F-1 / F-3 helpers land.

### 8. Security checklist — F-8

Four concerns came up during probe writing, all guarded inline:

- Path traversal in `id_<X>` → guarded by the probe's regex `/^[a-f0-9]{16}\.(png|jpg|pdf)$/`.
- MIME spoofing — `$_FILES['file']['type']` is client-supplied; the probe ignores it and uses `finfo` instead.
- Executable extension allowlist — `.php` / `.phtml` etc.; the probe encodes the extension from the mime, not from the original filename.
- Per-user scope — without `<user_id>` in the storage path, two users see each other's files; the probe includes `<user_id>` in the path.

Each was guarded by the contributor (me) thinking about it. No framework helper enforces them. A naive copy of the probe minus any one of the four guards ships a vulnerability.

**Finding (F-8)**: A dedicated `docs/review/file-upload.md` checklist (mirroring `rest-controller.md` / `html-controller.md`) or a section in `docs/development/file-uploads.md` listing the four concerns.

## Results

| Scenario | Expectation | Actual | Status |
| --- | --- | --- | --- |
| Read `$_FILES` via `$this->request->getFile('file')` | yes | no — must use `$_FILES` directly | Blocked |
| Upload valid 70-byte PNG → 200 + metadata | yes | yes (after probe checks) | Pass |
| Upload too-large (>1MB) → 413 ATTACHMENT-TOO-LARGE | yes | yes | Pass |
| Upload mime-mismatch → 415 ATTACHMENT-MIME-REJECTED | yes | yes | Pass |
| Upload without CSRF token → 403 CSRF-TOKEN-INVALID | yes | yes (framework auto-gate) | Pass |
| Retrieve binary in `Content-Type: image/png` body | yes | no — must base64-encode into JSON envelope | Blocked |
| Retrieve nonexistent id → 404 ATTACHMENT-NOT-FOUND | yes | yes (via `DomainException`) | Pass |
| Retrieve path-traversal id → rejected | yes | yes (probe regex) | Pass |
| `data/uploads/` exists after `init.sh` | yes | no — runtime-mkdir | Partial |
| `NENE_UPLOAD_MAX_FILESIZE` env override | nice-to-have | not present | Partial |
| `docs/api/openapi.yaml` covers multipart authoring | yes | no | Blocked |
| `composer test:http` contract test handles a multipart operation | nice-to-have | unverified | Unknown |
| `docs/tutorials/building-a-service.md` shows an upload form | yes | not present | Blocked |
| `docs/review/file-upload.md` exists | yes | not present | Blocked |

## Friction Summary

| ID  | Location                                            | Severity | Kind                 | Decision        |
| --- | --------------------------------------------------- | -------- | -------------------- | --------------- |
| F-1 | `class/xion/Request.php`                            | high     | feature-gap          | fix-in-framework |
| F-2 | `class/xion/ControllerBase.php::run`                | high     | design / feature-gap | fix-in-framework |
| F-3 | new helper                                          | medium   | feature-gap          | fix-in-framework |
| F-4 | `init.sh`                                           | low      | feature-gap          | document or fix  |
| F-5 | `docker/php/conf.d/`                                | medium   | feature-gap          | fix-in-framework |
| F-6 | `docs/api/openapi.yaml`, `OpenApiRuntimeContractTest` | medium  | feature-gap + docs   | fix-in-framework |
| F-7 | tutorial                                            | low      | docs-gap             | document        |
| F-8 | (no doc)                                            | low      | security / docs      | document        |

## Recommendations

### Immediate (small framework change)

1. **F-1 + F-3 + F-4 — One upload helpers PR.** `Request::getFile($key): ?UploadedFile`, `UploadedFile::validate($constraints): UploadedFile`, `init.sh` mkdir for `data/uploads/`. The three travel together.
2. **F-5 — PHP upload limits env-overridable.** Extend `docker/php/conf.d/zz-nene.ini` (or a sibling drop-in) with `NENE_UPLOAD_MAX_FILESIZE` / `NENE_POST_MAX_SIZE` interpolation.

### Immediate (design call, then ship)

1. **F-2 — REST handler binary response.** Decide between (a) `*Rest` returns `HttpResponse`, (b) new `*Binary` suffix, (c) `sendFile()` helper. (c) is the smallest and most surgical (no contract-test interaction); (a) is the most general but touches `run()`'s envelope. The trial does **not** prescribe — this is the design call the actual implementation PR should make.

### Immediate (documentation only)

1. **F-6 — Multipart in OpenAPI + contract test verification.** Add a `multipart/form-data` example to the review checklist; verify (and document) what `OpenApiRuntimeContractTest` does for such operations.
2. **F-7 + F-8 — `docs/development/file-uploads.md` + `docs/review/file-upload.md`.** Both can be one PR — they cross-reference each other and depend on F-1/F-3 having landed (so the helper signatures are stable).

### Trade-offs (no ADR needed)

The four candidate shapes for F-2 each have small footprints; none rises to ADR-class. Whichever is picked, document the choice inline in the PR description and the new `file-uploads.md` doc.

## Aftermath

- Probe controller (`AttachmentController.php`) and error-code rows stay inside the clone; not committed back to the framework.
- Five follow-up Issues filed against this report. F-7 / F-8 deferred only until F-1 / F-3 land (their doc references the new helper signatures).
- All Issues closed by merged PR before FT13 starts (per ADR-0002 cadence).
