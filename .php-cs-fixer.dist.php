<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/class',
        __DIR__ . '/cli',
        __DIR__ . '/config',
        __DIR__ . '/htdocs',
        __DIR__ . '/ini',
        __DIR__ . '/tests'
    ])
    ->name('*.php')
    ->exclude([
        'vendor'
    ]);

$config = new PhpCsFixer\Config();

return $config
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha'
        ],
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays']
        ]
    ])
    ->setFinder($finder);
