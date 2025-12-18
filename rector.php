<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/bootstrap',
        __DIR__ . '/vendor',
        __DIR__ . '/storage',
        __DIR__ . '/public',
        __DIR__ . '/resources',
        __DIR__ . '/node_modules',
        __DIR__ . '/examples',
        __DIR__ . '/database/migrations',
        __DIR__ . '/build',
        __DIR__ . '/coverage-report',
    ])

    // Performance optimizations
    ->withParallel(
        120,  // timeout seconds
        8,    // max processes (~60% of 14 cores on M4 Pro)
        10,    // job size (files per job)
    )
    ->withCache(__DIR__ . '/.rector-cache')

    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        strictBooleans: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_TESTING,
    ])
    ->withAttributesSets(phpunit: true);
