<?php

declare(strict_types=1);

/**
 * Insert a new error code into both `config/error_codes.php` and
 * `docs/development/error-codes.md` in one step, avoiding the manual
 * dance + the #352 drift gate (which catches the mistake of forgetting
 * one of the two files).
 *
 * Usage:
 *   php tools/error-code-add.php CODE "Message" <http-status> ["notes-for-docs"]
 *
 * Examples:
 *   php tools/error-code-add.php \
 *       TODO-LOCKED "TODO item is locked for editing." 409 \
 *       "Returned when another user already has the lock."
 *
 *   php tools/error-code-add.php \
 *       UPLOAD-DUPLICATE "Upload duplicates an existing file." 409
 *
 * Behavior:
 *   - Both files are edited in-place. The PHP file gets a new entry at
 *     the end of the array (before the closing `];`); the docs file
 *     gets a new table row at the end of the existing catalog block.
 *   - Refuses to add a code that already exists in either file.
 *   - Does not run the drift-gate test (the existing
 *     `tests/Unit/ErrorCodesDocSyncTest` does that after the edit).
 *
 * Exit codes:
 *   0  both files updated
 *   1  CLI usage error
 *   2  code already exists / file structure unexpected
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php tools/error-code-add.php CODE \"Message\" <http-status> [\"notes\"]\n");
    fwrite(STDERR, "       see the docblock at the top of this file for examples.\n");
    exit(1);
}

$code = (string)$argv[1];
$message = (string)$argv[2];
$httpStatus = (int)$argv[3];
$notes = isset($argv[4]) ? (string)$argv[4] : '';

if (!preg_match('/^[A-Z][A-Z0-9-]+$/', $code)) {
    fwrite(STDERR, "error: code must match /^[A-Z][A-Z0-9-]+\$/ (got: $code)\n");
    exit(1);
}
if ($httpStatus < 100 || $httpStatus > 599) {
    fwrite(STDERR, "error: HTTP status must be 100-599 (got: $httpStatus)\n");
    exit(1);
}

$frameworkRoot = dirname(__DIR__);
$configPath = $frameworkRoot . '/config/error_codes.php';
$docsPath = $frameworkRoot . '/docs/development/error-codes.md';

$configContent = (string)file_get_contents($configPath);
$docsContent = (string)file_get_contents($docsPath);

// Existence checks
if (str_contains($configContent, "'$code'")) {
    fwrite(STDERR, "error: `$code` already exists in $configPath\n");
    exit(2);
}
if (str_contains($docsContent, "`$code`")) {
    fwrite(STDERR, "error: `$code` already exists in $docsPath\n");
    exit(2);
}

// --- 1. Update config/error_codes.php ---
$entry = sprintf(
    "    '%s' => [\n        'message' => '%s',\n        'httpStatus' => %d,\n    ],\n",
    $code,
    addslashes($message),
    $httpStatus
);

// Insert before the closing `];` (last occurrence so we land at the array tail).
$lastClose = strrpos($configContent, '];');
if ($lastClose === false) {
    fwrite(STDERR, "error: $configPath does not end with `];` — structure unexpected\n");
    exit(2);
}
$updatedConfig = substr($configContent, 0, $lastClose) . $entry . substr($configContent, $lastClose);
file_put_contents($configPath, $updatedConfig);
echo "✓ updated $configPath\n";

// --- 2. Update docs/development/error-codes.md ---
// Find the last row of the catalog table (line starting with `| `…`) within
// the "## Catalog" section.
$lines = explode("\n", $docsContent);
$inCatalog = false;
$insertAfterIndex = null;
foreach ($lines as $index => $line) {
    if (str_starts_with($line, '## ')) {
        $inCatalog = ($line === '## Catalog');
        continue;
    }
    if ($inCatalog && str_starts_with($line, '| `')) {
        $insertAfterIndex = $index;
    }
}
if ($insertAfterIndex === null) {
    fwrite(STDERR, "error: could not locate `## Catalog` table rows in $docsPath\n");
    exit(2);
}

$noteCell = $notes === '' ? '' : str_replace('|', '\\|', $notes);
$newRow = sprintf(
    '| `%s` | %d | %s | %s |',
    $code,
    $httpStatus,
    str_replace('|', '\\|', $message),
    $noteCell
);
array_splice($lines, $insertAfterIndex + 1, 0, [$newRow]);
file_put_contents($docsPath, implode("\n", $lines));
echo "✓ updated $docsPath\n";

echo "\nReminder: run `composer test` to confirm the drift-gate (ErrorCodesDocSyncTest) passes.\n";
exit(0);
