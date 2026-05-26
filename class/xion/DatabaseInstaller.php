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
            'tables' => array_keys(SchemaDefinition::tables()),
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
            'environment' => defined('APP_ENV') ? APP_ENV : '',
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
            foreach (array_keys(SchemaDefinition::tables()) as $table) {
                self::assertTableExists($pdo, $table);
            }
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
     * Create MySQL tables from the PHP `SchemaDefinition` (ADR-0005).
     */
    private static function createMySQLTables(PDO $pdo): void
    {
        foreach (SchemaCompiler::mysqlStatements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Create SQLite tables (and their indexes) from the PHP
     * `SchemaDefinition` (ADR-0005).
     */
    private static function createSQLiteTables(PDO $pdo): void
    {
        foreach (SchemaCompiler::sqliteStatements() as $statement) {
            $pdo->exec($statement);
        }
        foreach (SchemaCompiler::sqliteIndexStatements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Apply SQLite triggers that simulate MySQL's
     * `ON UPDATE CURRENT_TIMESTAMP` on every `datetime-touch` column
     * (ADR-0005).
     */
    private static function createSQLiteTimestampTriggers(PDO $pdo): void
    {
        foreach (SchemaCompiler::sqliteTriggerStatements() as $statement) {
            $pdo->exec($statement);
        }
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
