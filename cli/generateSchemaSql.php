<?php

declare(strict_types=1);

/**
 * Regenerate `docker/mysql/init/001_schema.sql` from
 * {@see \Nene\Xion\SchemaDefinition} via {@see \Nene\Xion\SchemaCompiler}.
 *
 * Usage:
 *   php cli/generateSchemaSql.php [--check]
 *
 * Options:
 *   --check  Compare generated content against the existing file and
 *            exit with status 1 if they differ (no file write). Used
 *            by the drift unit test.
 *   --help   Show this help.
 */

use Nene\Xion\GenerateSchemaSqlCommand;

require_once dirname(__DIR__) . '/vendor/autoload.php';

exit((new GenerateSchemaSqlCommand())->execute());
