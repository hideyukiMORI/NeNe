<?php

declare(strict_types=1);

namespace Nene\Xion;

/**
 * NeNe's sample-schema source of truth.
 *
 * Every table that ships with the framework's bundled sample app
 * (`users`, `todos`, ...) is described here in PHP. The dialect-specific
 * DDL for MySQL and SQLite is then derived by {@see SchemaCompiler},
 * and the file `docker/mysql/init/001_schema.sql` becomes a generated
 * artifact (verified by `SchemaCompilerTest::testDockerInitSqlMatchesCompiledOutput`).
 *
 * Column type vocabulary (intentionally small — extend as the framework
 * grows, but keep it explicit so the dialect mapping stays auditable):
 *
 * - `pk-bigint`           → autoincrement big-int primary key
 * - `bigint`              → big-int column (typically a foreign key)
 * - `varchar:<length>`    → VARCHAR(length)
 * - `text`                → TEXT / TEXT
 * - `bool`                → boolean stored as TINYINT(1) / INTEGER
 * - `datetime-now`        → DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * - `datetime-touch`      → DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                            ON UPDATE CURRENT_TIMESTAMP (MySQL);
 *                            SQLite simulates via a trigger.
 *
 * See ADR-0005 for the consolidation rationale.
 */
final class SchemaDefinition
{
    /**
     * Return the sample schema as a structured PHP array.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tables(): array
    {
        return [
            'users' => [
                'columns' => [
                    'id'         => ['type' => 'pk-bigint'],
                    'created_at' => ['type' => 'datetime-now'],
                    'updated_at' => ['type' => 'datetime-touch'],
                    'user_id'    => ['type' => 'varchar:64'],
                    'user_pass'  => ['type' => 'varchar:255'],
                    'user_name'  => ['type' => 'varchar:255'],
                    'e_mail'     => ['type' => 'varchar:255'],
                    'is_deleted' => ['type' => 'bool', 'default' => 0],
                ],
                'unique' => [
                    'users_user_id_unique' => ['user_id'],
                ],
            ],
            'todos' => [
                'columns' => [
                    'id'           => ['type' => 'pk-bigint'],
                    'created_at'   => ['type' => 'datetime-now'],
                    'updated_at'   => ['type' => 'datetime-touch'],
                    'user_id'      => ['type' => 'bigint'],
                    'title'        => ['type' => 'varchar:255'],
                    'is_completed' => ['type' => 'bool', 'default' => 0],
                    'is_deleted'   => ['type' => 'bool', 'default' => 0],
                ],
                'indexes' => [
                    'todos_user_id_index' => ['user_id'],
                ],
                'foreign_keys' => [
                    'todos_user_id_foreign' => [
                        'columns'    => ['user_id'],
                        'references' => ['users', ['id']],
                        'on_delete'  => 'CASCADE',
                    ],
                ],
            ],
        ];
    }
}
