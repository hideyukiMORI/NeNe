# File Upload Self-Review

Use this checklist for any endpoint that accepts a `multipart/form-data` upload or serves binary file responses. Pairs with `rest-controller.md` (for the surrounding REST handler) or `html-controller.md` (for the HTML form).

Source policies:

- `docs/development/file-uploads.md` (helpers, storage convention, security summary)
- `docs/development/error-codes.md` (`UPLOAD-FILE-REQUIRED`, `UPLOAD-TOO-LARGE`, `UPLOAD-MIME-REJECTED`)
- `docs/development/production-deployment.md` (`NENE_UPLOAD_MAX_FILESIZE`, `NENE_POST_MAX_SIZE`)
- `docs/review/openapi-contract.md` (multipart operations)
- `docs/tutorials/building-a-service.md` § "Update OpenAPI" → "File upload (multipart) operations"

## Receive checklist

- [ ] Read the uploaded file via `$this->request->getFile('field-name')` — **not** `$_FILES['...']` directly.
- [ ] Call `->validate(['maxBytes' => N, 'allowedMime' => [...]])` on the wrapper. Do not skip any of the three validations (presence, size, mime).
- [ ] Treat `getFile()` returning `null` as "form field missing" — return `UPLOAD-FILE-REQUIRED` (or a per-endpoint code) explicitly.
- [ ] Use `UploadedFile::mime()` (the `finfo` result), **never** `$_FILES['...']['type']` (forgeable, client-supplied).
- [ ] Derive the stored-file extension from the validated mime (`match ($file->mime()) { 'image/png' => 'png', ... }`), not from `$_FILES['...']['name']`.
- [ ] Reject executable extensions on the stored path even when the mime is plausible (`.php`, `.phtml`, `.html`, `.htaccess`, `.shtml`).
- [ ] The save path includes the signed-in user (`data/uploads/<user_id>/...`) or another explicit scope identifier so a future id-parsing bug cannot leak files across users.
- [ ] The stored filename is server-generated (e.g. `bin2hex(random_bytes(8))`) — never the client-supplied name verbatim.
- [ ] `UploadedFile::moveTo($target)` is used (wraps `move_uploaded_file()` + re-checks `is_uploaded_file()`). Do not write the tmp file with `rename()` / `copy()`.
- [ ] State-changing upload endpoints declare both `sessionCookie` and `csrfToken` security in OpenAPI (`POST` requires CSRF).

## Serve / retrieve checklist

- [ ] The retrieve URL parameter is validated against an allowlist regex *before* being concatenated into a filesystem path (e.g. `/^[a-f0-9]{16}\.(png|jpg|pdf)$/`). No `basename()` shortcut.
- [ ] The resolved path stays under the per-user (or per-scope) storage directory. Adding `realpath()` and asserting `str_starts_with($real, $allowedBase)` is a good belt-and-suspenders extra.
- [ ] Binary responses use `$this->sendFile($path, $mime[, $downloadName])`, not custom `echo file_get_contents(...)` (the helper sets `Content-Type` and routes through `HttpEmitter`).
- [ ] Inline previews (`image`, `pdf`) omit `$downloadName`. Force-download endpoints pass it so browsers do not render arbitrary content.
- [ ] Missing files surface as `UPLOAD-FILE-REQUIRED` or fall through `$this->notFound()` — never a 500.

## OpenAPI checklist

- [ ] The operation's `requestBody.content['multipart/form-data'].schema` is a `type: object` with the file field declared as `type: string, format: binary`.
- [ ] Responses include the documented missing-file path (`400` with `UPLOAD-FILE-REQUIRED`) — the contract test probes multipart operations with an empty body and asserts the response status is documented.
- [ ] Auth-required upload operations `$ref` `#/components/responses/SessionClosed` (401) and `#/components/responses/CsrfTokenInvalid` (403) instead of inlining.
- [ ] Add `x-nene-runtime-probe: skip` only if the empty-body probe would do something destructive — for most uploads the validation rejection is the desired probe outcome and skip is unnecessary.

## Limits checklist

- [ ] PHP-level limit (`NENE_UPLOAD_MAX_FILESIZE` / `NENE_POST_MAX_SIZE`) covers the largest upload the deployment expects. `NENE_POST_MAX_SIZE >= NENE_UPLOAD_MAX_FILESIZE`.
- [ ] Controller-level `validate(['maxBytes' => N])` matches or is stricter than the PHP-level limit. Document the per-endpoint policy in the operation's OpenAPI `description:`.
- [ ] If the production deploy adds more upload endpoints with different per-endpoint maxima, each calls `validate()` with its own `maxBytes` rather than a global mutable constant.
