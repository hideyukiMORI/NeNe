<?php

declare(strict_types=1);

/**
 * Preflight for `composer test:http`.
 *
 * Prints a single line to stderr describing the HTTP smoke target before
 * PHPUnit runs, so the developer can tell at a glance whether the suite is
 * about to execute against a real runtime or skip every test silently.
 *
 * Exit code is always 0 so the surrounding composer script behavior is
 * unchanged. The goal is visibility, not enforcement.
 *
 * See:
 *   - docs/development/testing.md (HTTP smoke workflow)
 *   - #225 (background)
 */

$baseUrl = getenv('NENE_HTTP_BASE_URL');
$errorBaseUrl = getenv('NENE_HTTP_ERROR_BASE_URL');

$line = static function (string $text): void {
    fwrite(STDERR, $text . PHP_EOL);
};

$line('');
$line('[test:http] HTTP smoke preflight');

if ($baseUrl === false || $baseUrl === '') {
    $line('  NENE_HTTP_BASE_URL is not set.');
    $line('  Every HTTP test will be reported as Skipped. The PHPUnit summary');
    $line('  will still print "OK, but some tests were skipped!" — this is the');
    $line('  expected output when the runtime is not exercised.');
    $line('  To actually run the suite, see docs/development/testing.md.');
} else {
    $line('  NENE_HTTP_BASE_URL = ' . $baseUrl);
    if ($errorBaseUrl === false || $errorBaseUrl === '') {
        $line('  NENE_HTTP_ERROR_BASE_URL is not set (the error-exposure test will be skipped).');
    } else {
        $line('  NENE_HTTP_ERROR_BASE_URL = ' . $errorBaseUrl);
    }
}

$line('');
