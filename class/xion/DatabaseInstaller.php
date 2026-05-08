<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Database setup and health checks for the sample runtime.
 */
final class DatabaseInstaller
{
    /**
     * Create required sample tables and seed development data.
     *
     * @return array<string,mixed> Installation summary.
     */
    public static function install(): array
    {
        $pdo = self::pdo(true);
        self::createTables($pdo);
        if (DB_TYPE === 'SQLite3') {
            self::createSQLiteTimestampTriggers($pdo);
            self::syncSQLiteFilePermissions();
        }
        self::seedSampleData($pdo);

        return [
            'databaseType' => DB_TYPE,
            'databaseName' => DB_TYPE === 'MySQL' ? DB_NAME : DB_FILE,
            'tables' => ['users', 'todos'],
            'sampleUser' => 'admin',
        ];
    }

    /**
     * Check API, database connection, and sample schema status.
     *
     * @return array<string,mixed> Public health result.
     */
    public static function health(): array
    {
        $result = [
            'healthStatus' => 'degraded',
            'api' => true,
            'database' => false,
            'schema' => false,
            'databaseType' => defined('DB_TYPE') ? DB_TYPE : '',
        ];

        try {
            $pdo = self::pdo(false);
            $pdo->query('SELECT 1');
            $result['database'] = true;
        } catch (\Throwable $throwable) {
            return self::withDebugDetail($result, $throwable);
        }

        try {
            self::assertTableExists($pdo, 'users');
            self::assertTableExists($pdo, 'todos');
            $result['schema'] = true;
            $result['healthStatus'] = 'ok';
        } catch (\Throwable $throwable) {
            return self::withDebugDetail($result, $throwable);
        }

        return $result;
    }

    /**
     * Create a PDO connection without going through the HTTP termination path.
     */
    private static function pdo(bool $createSQLiteDirectory): PDO
    {
        if (!in_array(DB_TYPE, ['MySQL', 'SQLite3'], true)) {
            throw new \RuntimeException('Unsupported DB_TYPE: ' . DB_TYPE);
        }

        if (DB_TYPE === 'MySQL') {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $pdo->setAttribute((int)constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'), true);
            }
            return $pdo;
        }

        if ($createSQLiteDirectory && !is_dir(DB_DIR) && !mkdir(DB_DIR, 0775, true) && !is_dir(DB_DIR)) {
            throw new \RuntimeException('Database directory could not be created: ' . DB_DIR);
        }

        $pdo = new PDO('sqlite:' . DB_DIR . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    /**
     * Create the current sample tables for the configured database.
     */
    private static function createTables(PDO $pdo): void
    {
        if (DB_TYPE === 'MySQL') {
            self::createMySQLTables($pdo);
            return;
        }

        self::createSQLiteTables($pdo);
    }

    /**
     * Create MySQL tables aligned with Docker initialization.
     */
    private static function createMySQLTables(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_id VARCHAR(64) NOT NULL,
    user_pass VARCHAR(255) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    e_mail VARCHAR(255) NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY users_user_id_unique (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS todos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY todos_user_id_index (user_id),
    CONSTRAINT todos_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    /**
     * Create SQLite tables aligned with the MySQL sample layout.
     */
    private static function createSQLiteTables(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
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

        $pdo->exec(<<<'SQL'
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

        $pdo->exec('CREATE INDEX IF NOT EXISTS todos_user_id_index ON todos (user_id)');
    }

    /**
     * Keep SQLite updated_at close to MySQL's ON UPDATE CURRENT_TIMESTAMP.
     */
    private static function createSQLiteTimestampTriggers(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS users_updated_at_trigger
AFTER UPDATE ON users
FOR EACH ROW
WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END
SQL);

        $pdo->exec(<<<'SQL'
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
     * Insert sample account and TODO rows without duplicating them.
     */
    private static function seedSampleData(PDO $pdo): void
    {
        $adminPasswordHash = password_hash((string)(getenv('NENE_SAMPLE_ADMIN_PASSWORD') ?: 'admin'), PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO users (user_id, user_pass, user_name, e_mail, is_deleted)
SELECT 'admin', :user_pass, 'admin', 'admin@example.com', 0
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE user_id = 'admin'
)
SQL);
        $stmt->bindValue(':user_pass', $adminPasswordHash, PDO::PARAM_STR);
        $stmt->execute();

        self::seedTodo($pdo, 'Read the routing guide', true);
        self::seedTodo($pdo, 'Create a controller action', false);
    }

    /**
     * Insert one sample TODO when it does not exist yet.
     */
    private static function seedTodo(PDO $pdo, string $title, bool $isCompleted): void
    {
        $stmt = $pdo->prepare(<<<'SQL'
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
     * Verify that a table can be queried.
     */
    private static function assertTableExists(PDO $pdo, string $table): void
    {
        if (!in_array($table, ['users', 'todos'], true)) {
            throw new \InvalidArgumentException('Unexpected table check: ' . $table);
        }

        $pdo->query('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Match the SQLite file permission with the writable data directory.
     */
    private static function syncSQLiteFilePermissions(): void
    {
        $databasePath = DB_DIR . DB_FILE;
        $directoryGroup = filegroup(DB_DIR);
        if ($directoryGroup !== false) {
            @chgrp($databasePath, $directoryGroup);
        }

        @chmod($databasePath, 0664);
    }

    /**
     * Add diagnostic details only when public debug output is enabled.
     *
     * @param array<string,mixed> $result Public health result.
     *
     * @return array<string,mixed>
     */
    private static function withDebugDetail(array $result, \Throwable $throwable): array
    {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $result['detail'] = $throwable->getMessage();
        }

        return $result;
    }
}
