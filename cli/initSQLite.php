<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

use Nene\Xion\Initialize;

require_once dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo');

Initialize::init();

echo 'Welcome NeNe-PHP CLI!' . PHP_EOL . PHP_EOL;
echo 'This command initializes the SQLite database used by the fallback sample runtime.' . PHP_EOL;
echo 'Target file: ' . DB_DIR . DB_FILE . PHP_EOL . PHP_EOL;
echo 'Do you want to initialize SQLite? (Y/N)' . PHP_EOL;

$answer = trim((string)fgets(STDIN));
if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
    echo 'OK. Bye!' . PHP_EOL;
    return 0;
}

try {
    initializeSQLite(DB_DIR, DB_FILE);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SQLite initialization failed: ' . $exception->getMessage() . PHP_EOL);
    return 1;
}

echo 'Processing has been completed.' . PHP_EOL;
return 0;

/**
 * Initialize a SQLite database with the same sample tables as MySQL.
 */
function initializeSQLite(string $databaseDir, string $databaseFile): void
{
    if (!is_dir($databaseDir) && !mkdir($databaseDir, 0775, true) && !is_dir($databaseDir)) {
        throw new RuntimeException('Database directory could not be created: ' . $databaseDir);
    }

    $databasePath = $databaseDir . $databaseFile;
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    createTables($db);
    createTimestampTriggers($db);
    seedSampleData($db);
    syncDatabaseFilePermissions($databaseDir, $databasePath);

    echo 'SQLite database is ready: ' . $databasePath . PHP_EOL;
    echo 'Default development account: admin / admin' . PHP_EOL;
}

/**
 * Create tables aligned with docker/mysql/init/001_schema.sql.
 */
function createTables(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id TEXT NOT NULL,
    user_pass TEXT NOT NULL,
    user_name TEXT NOT NULL,
    e_mail TEXT NOT NULL,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    UNIQUE (user_id)
)
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    is_completed INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
)
SQL);

    $db->exec('CREATE INDEX IF NOT EXISTS todos_user_id_index ON todos (user_id)');
}

/**
 * Keep updated_at close to MySQL's ON UPDATE CURRENT_TIMESTAMP behavior.
 */
function createTimestampTriggers(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS users_updated_at_trigger
AFTER UPDATE ON users
FOR EACH ROW
WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END
SQL);

    $db->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS todos_updated_at_trigger
AFTER UPDATE ON todos
FOR EACH ROW
WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE todos SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END
SQL);
}

/**
 * Insert the default development account and TODO samples idempotently.
 */
function seedSampleData(PDO $db): void
{
    $adminPasswordHash = password_hash((string)(getenv('NENE_SAMPLE_ADMIN_PASSWORD') ?: 'admin'), PASSWORD_DEFAULT);

    $stmt = $db->prepare(<<<'SQL'
INSERT INTO users (user_id, user_pass, user_name, e_mail, is_deleted)
SELECT 'admin', :user_pass, 'admin', 'admin@example.com', 0
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE user_id = 'admin'
)
SQL);
    $stmt->bindValue(':user_pass', $adminPasswordHash, PDO::PARAM_STR);
    $stmt->execute();

    seedTodo($db, 'Read the routing guide', true);
    seedTodo($db, 'Create a controller action', false);
}

/**
 * Insert a sample TODO for the admin user when it does not exist yet.
 */
function seedTodo(PDO $db, string $title, bool $isCompleted): void
{
    $stmt = $db->prepare(<<<'SQL'
INSERT INTO todos (user_id, title, is_completed, is_deleted)
SELECT users.id, :title, :is_completed, 0
FROM users
WHERE users.user_id = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM todos
    WHERE todos.user_id = users.id
    AND todos.title = :title
)
SQL);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':is_completed', $isCompleted ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Match the SQLite file permission with the writable data directory.
 */
function syncDatabaseFilePermissions(string $databaseDir, string $databasePath): void
{
    $directoryGroup = filegroup($databaseDir);
    if ($directoryGroup !== false) {
        @chgrp($databasePath, $directoryGroup);
    }

    @chmod($databasePath, 0664);
}
