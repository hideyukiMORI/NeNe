<?php

declare(strict_types=1);

return [
    'target_php_version' => '8.4',
    'minimum_target_php_version' => '8.4',
    'directory_list' => [
        'class',
        'cli',
        'config',
        'htdocs',
        'ini',
        'tests',
        'vendor'
    ],
    'exclude_analysis_directory_list' => [
        'vendor'
    ],
    'exclude_file_list' => [
        '.php-cs-fixer.dist.php'
    ],
    'suppress_issue_types' => [
        // The baseline records existing issues; keep this list for noisy
        // environment-level checks that do not help with incremental cleanup.
        'PhanPluginRemoveDebugAny'
    ],
    'allow_polyfill_parser' => true,
    'dead_code_detection' => false,
    'unused_variable_detection' => false,
    'quick_mode' => true
];
