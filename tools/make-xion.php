#!/usr/bin/env php
<?php

/**
 * Scaffold a new Xion class + matching test file.
 *
 * Usage:
 *   php tools/make-xion.php ClassName
 *   composer make:xion -- ClassName
 *
 * Creates:
 *   class/xion/ClassName.php          — PDO injection skeleton
 *   tests/Unit/Xion/ClassNameTest.php — in-memory SQLite test skeleton
 */

declare(strict_types=1);

// ── CLI guard ────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$name = $argv[1] ?? null;

if ($name === null || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
    fwrite(STDERR, "Usage: php tools/make-xion.php ClassName\n");
    fwrite(STDERR, "  ClassName must be PascalCase (e.g. UserWidget)\n");
    exit(1);
}

// ── Paths ────────────────────────────────────────────────────────────────────

$root     = dirname(__DIR__);
$classFile = "{$root}/class/xion/{$name}.php";
$testFile  = "{$root}/tests/Unit/Xion/{$name}Test.php";

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

namespace Nene\Xion;

use Nene\Database\PdoConnection;
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

namespace Tests\Unit\Xion;

use Nene\Xion\\{$name};
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

// ── Summary ───────────────────────────────────────────────────────────────────

echo "Created:\n";
echo "  class/xion/{$name}.php\n";
echo "  tests/Unit/Xion/{$name}Test.php\n";
echo "\n";
echo "Next steps:\n";
echo "  1. Edit the class body and add your public methods\n";
echo "  2. Add the table DDL to class/xion/SchemaDefinition.php\n";
echo "  3. Add an entry to class/xion/INDEX.md  (or run: composer xion:index)\n";
echo "  4. Write your tests\n";
echo "  5. composer analyze:file -- class/xion/{$name}.php tests/Unit/Xion/{$name}Test.php\n";
