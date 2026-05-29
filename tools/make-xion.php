#!/usr/bin/env php
<?php

/**
 * Scaffold a new Xion (framework-core) or Kit (helper-catalogue) class + test.
 *
 * Usage:
 *   composer make:kit  -- ClassName     (→ class/kit/,  Nene\Kit)   ← field-trial helpers
 *   composer make:xion -- ClassName     (→ class/xion/, Nene\Xion)  ← framework core (rare)
 *   php tools/make-xion.php [--kit|--xion] ClassName
 *
 * Creates the class skeleton + an in-memory SQLite test skeleton and registers
 * the class in the target INDEX.md. See ADR-0014 for the core/helper boundary.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ── Parse target flag + class name ────────────────────────────────────────────

$target = 'xion';
$args   = array_slice($argv, 1);
if (($args[0] ?? '') === '--kit') {
    $target = 'kit';
    array_shift($args);
} elseif (($args[0] ?? '') === '--xion') {
    array_shift($args);
}
$name = $args[0] ?? null;

if ($name === null || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
    fwrite(STDERR, "Usage: php tools/make-xion.php [--kit|--xion] ClassName\n");
    fwrite(STDERR, "  ClassName must be PascalCase (e.g. UserWidget)\n");
    exit(1);
}

// ── Target configuration ──────────────────────────────────────────────────────

$ns       = $target === 'kit' ? 'Nene\\Kit' : 'Nene\\Xion';
$testNs    = $target === 'kit' ? 'Nene\\Tests\\Unit\\Kit' : 'Nene\\Tests\\Unit\\Xion';
$root      = dirname(__DIR__);
$classDir  = "{$root}/class/{$target}";
$testDir   = "{$root}/tests/Unit/" . ($target === 'kit' ? 'Kit' : 'Xion');
$classFile = "{$classDir}/{$name}.php";
$testFile  = "{$testDir}/{$name}Test.php";

// Kit classes live in a different namespace from PdoConnection (Nene\Xion).
$pdoImport = $target === 'kit' ? "\nuse Nene\\Xion\\PdoConnection;" : '';

foreach ([$classDir, $testDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}
foreach ([$classFile, $testFile] as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "Error: already exists — {$path}\n");
        exit(1);
    }
}

// ── Templates ────────────────────────────────────────────────────────────────

$classBody = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};
{$pdoImport}
use PDO;

/**
 * {$name} — TODO: one-line description.
 */
final class {$name}
{
    public function __construct(private readonly ?PDO \$db = null) {}

    // ── public API ────────────────────────────────────────────────────────────

    // TODO: implement methods

    // ── private ───────────────────────────────────────────────────────────────

    private function db(): PDO
    {
        return \$this->db ?? PdoConnection::getInstance();
    }
}
PHP;

// Convert PascalCase to snake_case for the default table name hint
$snake = strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)) ?? $name);

$testBody = <<<PHP
<?php

declare(strict_types=1);

namespace {$testNs};

use {$ns}\\{$name};
use PDO;
use PHPUnit\Framework\TestCase;

final class {$name}Test extends TestCase
{
    private PDO \$pdo;

    protected function setUp(): void
    {
        \$this->pdo = new PDO('sqlite::memory:');
        \$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \$this->pdo->exec('
            CREATE TABLE {$snake} (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    // TODO: add tests
}
PHP;

// ── Write ─────────────────────────────────────────────────────────────────────

file_put_contents($classFile, $classBody . "\n");
file_put_contents($testFile,  $testBody  . "\n");

// ── Register in INDEX.md ──────────────────────────────────────────────────────

$indexScript = __DIR__ . '/xion-index.php';
if (file_exists($indexScript)) {
    passthru("php {$indexScript} {$target}", $indexExit);
    if ($indexExit !== 0) {
        fwrite(STDERR, "Warning: xion-index.php exited with code {$indexExit}\n");
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────

$relClass = "class/{$target}/{$name}.php";
$relTest  = 'tests/Unit/' . ($target === 'kit' ? 'Kit' : 'Xion') . "/{$name}Test.php";

echo "Created:\n";
echo "  {$relClass}\n";
echo "  {$relTest}\n";
echo "  class/{$target}/INDEX.md updated (Uncategorized)\n";
echo "\n";
echo "Next steps:\n";
echo "  1. Edit the class body and add your public methods\n";
echo "  2. Move the Uncategorized entry in class/{$target}/INDEX.md to the right section\n";
echo "  3. Write your tests\n";
echo "  4. composer analyze:file -- {$relClass} {$relTest}\n";
