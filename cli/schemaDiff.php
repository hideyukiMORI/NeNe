<?php

declare(strict_types=1);

/**
 * Compare a running database's schema against {@see \Nene\Xion\SchemaDefinition}
 * and emit the SQL that would converge the live DB to the definition.
 *
 * The CLI never applies SQL. See ADR-0009 for rationale.
 *
 * Usage:
 *   php cli/schemaDiff.php --dsn=<pdo-dsn> [--user=<u>] [--pass=<p>]
 *   php cli/schemaDiff.php --help
 */

use Nene\Xion\SchemaDiffCommand;

require_once dirname(__DIR__) . '/vendor/autoload.php';

exit((new SchemaDiffCommand())->execute());
