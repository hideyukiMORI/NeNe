#!/usr/bin/env php
<?php

/**
 * Regenerate class/xion/INDEX.md.
 *
 * - Updates descriptions of existing entries from each class's PHPDoc.
 * - Removes entries whose class file no longer exists.
 * - Appends newly discovered classes to an "## Uncategorized" section.
 * - Preserves all section headings, category order, and table structure.
 *
 * Usage:
 *   php tools/xion-index.php
 *   composer xion:index
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);

// Target namespace directory: 'xion' (framework core) or 'kit' (helper catalogue).
$target = $argv[1] ?? 'xion';
if (!in_array($target, ['xion', 'kit'], true)) {
    fwrite(STDERR, "Usage: php tools/xion-index.php [xion|kit]\n");
    exit(1);
}
$indexPath = "{$root}/class/{$target}/INDEX.md";

// ── Load existing INDEX ───────────────────────────────────────────────────────

if (!file_exists($indexPath)) {
    fwrite(STDERR, "Error: {$indexPath} not found\n");
    exit(1);
}

$original = file_get_contents($indexPath);
assert(is_string($original));

// ── Discover all current class files ─────────────────────────────────────────

/** @var array<string,string> $classDesc  className => description */
$classDesc = [];

foreach (glob("{$root}/class/{$target}/*.php") ?: [] as $file) {
    $base = basename($file, '.php');
    $src  = (string)file_get_contents($file);

    $desc = '';
    // First non-empty, non-tag docblock line
    if (preg_match('#/\*\*\s*\n\s*\*\s*([^\n@*][^\n]+?)\s*\n#', $src, $m)) {
        $desc = trim($m[1]);
        $desc = ltrim($desc, '* ');
        // Strip "ClassName — " prefix if present
        $desc = (string)preg_replace('/^' . preg_quote($base, '/') . '\s*[—–-]\s*/u', '', $desc);
        // Discard placeholder descriptions
        if (str_contains($desc, 'AYANE') || strlen($desc) < 5) {
            $desc = '';
        }
    }

    $classDesc[$base] = $desc !== '' ? $desc : '—';
}

// ── Classes already mentioned in the INDEX ────────────────────────────────────

$mentionedPattern = '/^\|\s*`([A-Za-z0-9]+)`\s*\|/m';
preg_match_all($mentionedPattern, $original, $matches);
/** @var string[] $mentioned */
$mentioned = $matches[1];

// ── Update description for each existing row ─────────────────────────────────

$updated  = $original;
$removed  = [];
$outdated = [];

foreach ($mentioned as $cls) {
    if (!isset($classDesc[$cls])) {
        // Class file deleted — mark for removal
        $removed[] = $cls;
        $updated = (string)preg_replace(
            '/^\|[^\n]*`' . preg_quote($cls, '/') . '`[^\n]*\n/m',
            '',   // remove the row
            $updated
        );
        continue;
    }

    // Replace description column
    $newRow  = "| `{$cls}` | {$classDesc[$cls]} |";
    $updated = (string)preg_replace(
        '/^\|\s*`' . preg_quote($cls, '/') . '`\s*\|[^\n]*/m',
        $newRow,
        $updated
    );
}

// ── Append newly discovered classes ──────────────────────────────────────────

$mentionedSet = array_flip($mentioned);
/** @var string[] $newClasses */
$newClasses = [];
foreach ($classDesc as $cls => $_) {
    if (!isset($mentionedSet[$cls])) {
        $newClasses[] = $cls;
    }
}

// Remove any existing empty Uncategorized section first (table has no data rows)
$updated = (string)preg_replace(
    '/\n---\n\n## Uncategorized\n\n_[^\n]+_\n\n\| Class \| Description \|\n\|[-| ]+\|\n\n?/s',
    "\n",
    $updated
);

if ($newClasses !== []) {
    // Strip trailing newlines then append the section
    $updated = rtrim($updated) . "\n\n";
    $updated .= "---\n\n## Uncategorized\n\n";
    $updated .= "_Move these rows to the appropriate section above._\n\n";
    $updated .= "| Class | Description |\n";
    $updated .= "|-------|-------------|\n";
    foreach ($newClasses as $cls) {
        $updated .= "| `{$cls}` | {$classDesc[$cls]} |\n";
    }
    $updated .= "\n";
}

// ── Write output ──────────────────────────────────────────────────────────────

file_put_contents($indexPath, $updated);

// ── Report ────────────────────────────────────────────────────────────────────

$countExisting = count($mentioned) - count($removed);
echo "class/{$target}/INDEX.md updated\n";
echo "  " . count($classDesc) . " classes in class/{$target}/\n";
echo "  {$countExisting} existing entries refreshed\n";

if ($removed !== []) {
    echo "  removed (class deleted): " . implode(', ', $removed) . "\n";
}

if ($newClasses !== []) {
    echo "  added to Uncategorized: " . implode(', ', $newClasses) . "\n";
    echo "  → Move them to the correct section in class/{$target}/INDEX.md\n";
}
