<?php

declare(strict_types=1);

/**
 * One-off migration helper for ADR-0014: move application-domain helper classes
 * out of Nene\Xion (class/xion/) into Nene\Kit (class/kit/), batch by batch.
 *
 * Usage:
 *   php tools/migrate-to-kit.php preflight
 *       List the full MOVE set (everything not in the STAY allowlist) and flag
 *       any MOVE candidate that a STAY (core) class references by name — i.e. a
 *       likely mis-classification that must stay in Xion.
 *
 *   php tools/migrate-to-kit.php move Foo Bar Baz
 *       Move the named classes: git mv class/xion → class/kit, rewrite the
 *       namespace to Nene\Kit, inject `use Nene\Xion\<core>;` for referenced
 *       core symbols, and move + rewrite the matching test file.
 *
 * After a `move`, run: composer dump-autoload && composer format && composer test
 */

$root = dirname(__DIR__);

/** Core + platform classes that REMAIN in Nene\Xion (framework, not helpers). */
const STAY = [
    // HTTP layer / runtime
    'ControllerBase', 'Dispatcher', 'HttpEmitter', 'HttpResponse', 'HttpTermination',
    'JsonResponder', 'RouteContext', 'QueryString', 'UrlParameter', 'RedirectGuard',
    'RoleGuard', 'Request', 'RequestVariables', 'Post', 'View', 'Cursor', 'CursorPage',
    'OffsetPage', 'ApiResponse', 'HttpCache', 'UploadedFile', 'FileUpload',
    // DB base / schema / CLI
    'PdoConnection', 'DataMapperBase', 'DataModelBase', 'ModelBase', 'TransactionManager',
    'DbUpsert', 'SchemaDefinition', 'SchemaCompiler', 'SchemaDiffer', 'SchemaDiffCommand',
    'GenerateSchemaSqlCommand', 'InitSqliteCommand', 'SetupDatabaseCommand', 'Command',
    'EnvLoader', 'Initialize', 'DatabaseInstaller',
    // errors / observability / mail / logging
    'ErrorCode', 'DomainException', 'Log', 'LogFormatterFactory', 'AuditLogger',
    'RequestId', 'ResponseDecorator', 'Mailer', 'MailMessage',
    // auth / session plumbing
    'AuthSession', 'CsrfProtectionPolicy', 'SessionHandlerFactory', 'RedisSessionHandler',
    'BearerAuth', 'JwtCodec',
    // cross-cutting platform services referenced by core
    'ServerTiming',
];

/** Core symbols a helper may legitimately reference and therefore need a `use`. */
const CORE_USABLE = [
    'PdoConnection', 'DbUpsert', 'DomainException', 'TransactionManager',
    'ErrorCode', 'ApiResponse', 'SchemaDefinition',
];

/** @return list<string> all class basenames in class/xion/ */
function xionClasses(string $root): array
{
    $out = [];
    foreach (glob($root . '/class/xion/*.php') ?: [] as $f) {
        $out[] = basename($f, '.php');
    }
    sort($out);

    return $out;
}

/** @return list<string> classes that should move to Kit */
function moveSet(string $root): array
{
    return array_values(array_filter(
        xionClasses($root),
        static fn (string $c): bool => !in_array($c, STAY, true),
    ));
}

/** Does $file reference identifier $name as code (rough word-boundary check)? */
function referencesSymbol(string $file, string $name): bool
{
    $src = (string)file_get_contents($file);

    return preg_match('/\b' . preg_quote($name, '/') . '\b/', $src) === 1;
}

$cmd  = $argv[1] ?? '';
$args = array_slice($argv, 2);

if ($cmd === 'preflight') {
    $move = moveSet($root);
    echo 'MOVE set: ' . count($move) . " classes (STAY: " . count(STAY) . ")\n\n";

    $conflicts = [];
    foreach ($move as $cls) {
        foreach (STAY as $core) {
            $coreFile = $root . "/class/xion/{$core}.php";
            if (!is_file($coreFile)) {
                continue;
            }
            if (referencesSymbol($coreFile, $cls)) {
                $conflicts[$cls][] = $core;
            }
        }
    }

    if ($conflicts === []) {
        echo "No conflicts: no STAY class references any MOVE candidate. Safe to batch.\n";
    } else {
        echo "⚠ CONFLICTS — these MOVE candidates are referenced by core (should likely STAY):\n";
        foreach ($conflicts as $cls => $by) {
            echo "  {$cls} ← " . implode(', ', $by) . "\n";
        }
    }
    exit($conflicts === [] ? 0 : 1);
}

if ($cmd === 'move') {
    if ($args === []) {
        fwrite(STDERR, "Usage: move <Class> [Class...]\n");
        exit(1);
    }
    $move = moveSet($root);

    foreach ($args as $cls) {
        if (in_array($cls, STAY, true)) {
            fwrite(STDERR, "SKIP {$cls}: in STAY allowlist (core).\n");
            continue;
        }
        if (!in_array($cls, $move, true)) {
            fwrite(STDERR, "SKIP {$cls}: not found in class/xion/.\n");
            continue;
        }

        // ── class file ──
        $from = $root . "/class/xion/{$cls}.php";
        $to   = $root . "/class/kit/{$cls}.php";
        $src  = (string)file_get_contents($from);

        $src = str_replace('namespace Nene\\Xion;', 'namespace Nene\\Kit;', $src);

        // Determine needed core imports: any STAY class the moved body references
        // must now be imported from Nene\Xion.
        $imports = [];
        foreach (STAY as $core) {
            if (preg_match('/\b' . preg_quote($core, '/') . '\b/', $src) === 1) {
                $imports[] = "use Nene\\Xion\\{$core};";
            }
        }
        sort($imports);
        if ($imports !== []) {
            $importBlock = implode("\n", $imports) . "\n";
            if (preg_match('/^use /m', $src) === 1) {
                // Insert before the first existing `use ` line (CS Fixer sorts).
                $src = preg_replace('/^use /m', $importBlock . 'use ', $src, 1) ?? $src;
            } else {
                // No existing imports: insert a use block after the namespace line.
                $src = preg_replace(
                    '/^(namespace Nene\\\\Kit;\n)/m',
                    "$1\n" . $importBlock,
                    $src,
                    1,
                ) ?? $src;
            }
        }

        file_put_contents($to, $src);
        exec('git -C ' . escapeshellarg($root) . ' rm -q ' . escapeshellarg($from));
        exec('git -C ' . escapeshellarg($root) . ' add ' . escapeshellarg($to));
        echo "moved class: {$cls}\n";

        // ── test file ──
        $tFrom = $root . "/tests/Unit/Xion/{$cls}Test.php";
        $tTo   = $root . "/tests/Unit/Kit/{$cls}Test.php";
        if (is_file($tFrom)) {
            @mkdir(dirname($tTo), 0777, true);
            $tsrc = (string)file_get_contents($tFrom);
            // Normalise both historical test-namespace conventions to the
            // canonical PSR-4 one under Kit.
            $tsrc = str_replace(
                ['namespace Nene\\Tests\\Unit\\Xion;', 'namespace Tests\\Unit\\Xion;'],
                'namespace Nene\\Tests\\Unit\\Kit;',
                $tsrc,
            );
            $tsrc = str_replace("use Nene\\Xion\\{$cls};", "use Nene\\Kit\\{$cls};", $tsrc);
            file_put_contents($tTo, $tsrc);
            exec('git -C ' . escapeshellarg($root) . ' rm -q ' . escapeshellarg($tFrom));
            exec('git -C ' . escapeshellarg($root) . ' add ' . escapeshellarg($tTo));
            echo "moved test:  {$cls}Test\n";
        } else {
            echo "  (no test file for {$cls})\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "Usage: php tools/migrate-to-kit.php {preflight|move <Class...>}\n");
exit(1);
