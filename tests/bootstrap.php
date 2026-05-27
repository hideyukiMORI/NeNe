<?php
// Local-dev bootstrap for git worktrees.
// On CI the vendor/ directory is freshly installed and Composer's generated
// autoload_psr4.php already maps Nene\Func\, Nene\Xion\, and Nene\Tests\
// relative to the project root, so this file is a no-op there.
//
// Locally the vendor/ directory is a symlink to the main NeNe checkout, whose
// generated paths do NOT include this worktree's class/ or tests/ directories.
// We therefore add them manually so phpunit can resolve classes without
// needing a full `composer install` inside the worktree.

$loader = require __DIR__ . '/../vendor/autoload.php';
$root   = dirname(__DIR__);

// Only add if not already registered (avoids double-registration on CI or when
// running from the main NeNe checkout where the path already matches).
$existing = $loader->getPrefixesPsr4()['Nene\\Func\\'] ?? [];
if (!in_array($root . '/class/func', $existing, true)) {
    $loader->addPsr4('Nene\\Func\\', [$root . '/class/func']);
}
$existingTests = $loader->getPrefixesPsr4()['Nene\\Tests\\'] ?? [];
if (!in_array($root . '/tests', $existingTests, true)) {
    $loader->addPsr4('Nene\\Tests\\', [$root . '/tests']);
}
