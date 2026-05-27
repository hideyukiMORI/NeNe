#!/usr/bin/env php
<?php

/**
 * Mark an FT as done across the three tracking documents.
 *
 * Usage:
 *   php tools/ft-done.php FT265 UserWidget "short description" 712
 *   composer ft:done -- FT265 UserWidget "short description" 712
 *
 * Updates:
 *   docs/todo/current.md           — advances FT count + date in status line
 *   docs/field-trials/candidates.md — prepends archive entry
 *   docs/roadmap.md                — advances FT count + date in status line
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ── Argument parsing ──────────────────────────────────────────────────────────

$ftRaw  = $argv[1] ?? null;
$class  = $argv[2] ?? null;
$desc   = $argv[3] ?? null;
$prRaw  = $argv[4] ?? null;

if ($ftRaw === null || $class === null || $desc === null || $prRaw === null) {
    fwrite(STDERR, "Usage: php tools/ft-done.php FT265 ClassName \"description\" 712\n");
    fwrite(STDERR, "  FT265    — FT number (with or without 'FT' prefix)\n");
    fwrite(STDERR, "  ClassName — PascalCase class name\n");
    fwrite(STDERR, "  \"desc\"   — one-line description (quote it)\n");
    fwrite(STDERR, "  712      — merged PR number\n");
    exit(1);
}

// Normalise FT number
$ftNum = (int) ltrim($ftRaw, 'FTft');
if ($ftNum < 1) {
    fwrite(STDERR, "Error: FT number must be a positive integer (got: {$ftRaw})\n");
    exit(1);
}
$ftLabel = "FT{$ftNum}";

$prNum = (int) ltrim($prRaw, '#');
if ($prNum < 1) {
    fwrite(STDERR, "Error: PR number must be a positive integer (got: {$prRaw})\n");
    exit(1);
}

$today = date('Y-m-d');

// ── File paths ────────────────────────────────────────────────────────────────

$root         = dirname(__DIR__);
$todoPath     = "{$root}/docs/todo/current.md";
$candidatePath = "{$root}/docs/field-trials/candidates.md";
$roadmapPath  = "{$root}/docs/roadmap.md";

foreach ([$todoPath, $candidatePath, $roadmapPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Error: not found — {$path}\n");
        exit(1);
    }
}

// ── 1. docs/todo/current.md ──────────────────────────────────────────────────
//
// Target line (in ## Active):
//   "... As of YYYY-MM-DD: all non-promotion Issues are closed;
//    FT1–FT264 are complete ..."
//
// Replace "FT1–FT\d+ are complete" and "As of YYYY-MM-DD:" in that line only.

$todo = (string) file_get_contents($todoPath);

// Update FT count in the active-section status line (matches "FT1–FTn are complete")
$todoNew = (string) preg_replace(
    '/FT1–FT\d+\s+are complete/',
    "FT1–{$ftLabel} are complete",
    $todo,
    1   // first occurrence only (the active-section line)
);

// Update date in the same line
$todoNew = (string) preg_replace(
    '/As of \d{4}-\d{2}-\d{2}:/',
    "As of {$today}:",
    $todoNew,
    1
);

if ($todoNew === $todo) {
    fwrite(STDERR, "Warning: docs/todo/current.md — no pattern matched, file unchanged\n");
} else {
    file_put_contents($todoPath, $todoNew);
    echo "  docs/todo/current.md           updated (FT1–{$ftLabel})\n";
}

// ── 2. docs/field-trials/candidates.md ───────────────────────────────────────
//
// Prepend a new archive entry just after the "archive trail" header + blank
// line:
//
//   ## Recently picked-up (archive trail)
//   <blank>
//   When a candidate...
//   <blank>
//   - **FT264 — ...  ← insert new line here

$candidates = (string) file_get_contents($candidatePath);

$archiveLine = "- **{$ftLabel} — {$class}** ({$today}):"
             . " `Nene\\Xion\\{$class}` — {$desc}. PR #{$prNum}.";

// Insert before the first "- **FT" line in the archive section
$candidatesNew = (string) preg_replace(
    '/^(- \*\*FT\d+)/m',
    $archiveLine . "\n" . '$1',
    $candidates,
    1
);

if ($candidatesNew === $candidates) {
    fwrite(STDERR, "Warning: docs/field-trials/candidates.md — no archive line found, appending\n");
    // Fall back: append to end of file
    $candidatesNew = rtrim($candidates) . "\n" . $archiveLine . "\n";
}

file_put_contents($candidatePath, $candidatesNew);
echo "  docs/field-trials/candidates.md updated (prepended {$ftLabel})\n";

// ── 3. docs/roadmap.md ───────────────────────────────────────────────────────
//
// Target line (in ## 7. Field Trials):
//   "Status: ... FT1–FT264 complete as of YYYY-MM-DD ..."

$roadmap = (string) file_get_contents($roadmapPath);

$roadmapNew = (string) preg_replace(
    '/FT1–FT\d+\s+complete as of \d{4}-\d{2}-\d{2}/',
    "FT1–{$ftLabel} complete as of {$today}",
    $roadmap,
    1
);

if ($roadmapNew === $roadmap) {
    fwrite(STDERR, "Warning: docs/roadmap.md — no pattern matched, file unchanged\n");
} else {
    file_put_contents($roadmapPath, $roadmapNew);
    echo "  docs/roadmap.md                updated (FT1–{$ftLabel})\n";
}

// ── Done ──────────────────────────────────────────────────────────────────────

echo "\n✓ {$ftLabel} — {$class} marked done (PR #{$prNum})\n";
