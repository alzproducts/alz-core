<?php

declare(strict_types=1);
use App\Console\GitHooks\PestPrePushHook;
use App\Console\GitHooks\PHPInsightsPrePushHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\LarastanPreCommitHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\PintPreCommitHook;

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-Commit Hooks
    |--------------------------------------------------------------------------
    | Fast linting checks on staged files - runs before commit (5-10 seconds)
    | PHP Insights is too slow and better suited for pre-push
    */
    'pre-commit' => [
        PintPreCommitHook::class,
        LarastanPreCommitHook::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-Push Hooks
    |--------------------------------------------------------------------------
    | Comprehensive checks on entire codebase - runs before pushing to remote
    | Runs all tests and full code quality analysis (20-30 seconds)
    */
    'pre-push' => [
        PestPrePushHook::class,
        PHPInsightsPrePushHook::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Other Hooks
    |--------------------------------------------------------------------------
    */
    'prepare-commit-msg' => [],
    'commit-msg' => [],
    'post-commit' => [],
    'pre-rebase' => [],
    'post-rewrite' => [],
    'post-checkout' => [],
    'post-merge' => [],

    /*
    |--------------------------------------------------------------------------
    | Laravel Sail
    |--------------------------------------------------------------------------
    | Enable this to run all commands through Laravel Sail
    | Required for this project since we use PHP 8.4 in Docker
    */
    'use_sail' => \env('GITHOOKS_USE_SAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Configuration (Using defaults from vendor config)
    |--------------------------------------------------------------------------
    */
    'code_analyzers' => [
        'laravel_pint' => [
            'path' => \env('LARAVEL_PINT_PATH', 'vendor/bin/pint'),
            'config' => \env('LARAVEL_PINT_CONFIG', 'pint.json'),
            'preset' => \env('LARAVEL_PINT_PRESET'),
            'file_extensions' => \env('LARAVEL_PINT_FILE_EXTENSIONS', '/\.php$/'),
            'run_in_docker' => \env('LARAVEL_PINT_RUN_IN_DOCKER', false),
            'docker_container' => \env('LARAVEL_PINT_DOCKER_CONTAINER', ''),
        ],
        'larastan' => [
            'path' => \env('LARASTAN_PATH', 'vendor/bin/phpstan'),
            'config' => \env('LARASTAN_CONFIG', 'phpstan.neon'),
            'additional_params' => \env('LARASTAN_ADDITIONAL_PARAMS', ''),
            'file_extensions' => \env('LARASTAN_FILE_EXTENSIONS', '/\.php$/'),
            'run_in_docker' => \env('LARASTAN_RUN_IN_DOCKER', false),
            'docker_container' => \env('LARASTAN_DOCKER_CONTAINER', ''),
        ],
        'phpinsights' => [
            'path' => \env('PHPINSIGHTS_PATH', 'vendor/bin/phpinsights'),
            'config' => \env('PHPINSIGHTS_CONFIG', 'config/insights.php'),
            'file_extensions' => \env('PHPINSIGHTS_FILE_EXTENSIONS', '/\.php$/'),
            'additional_params' => \env('PHPINSIGHTS_ADDITIONAL_PARAMS', ''),
            'run_in_docker' => \env('PHPINSIGHTS_RUN_IN_DOCKER', false),
            'docker_container' => \env('PHPINSIGHTS_DOCKER_CONTAINER', ''),
        ],
    ],

    'artisan_path' => \base_path('artisan'),
    'validate_paths' => \env('GITHOOKS_VALIDATE_PATHS', true),
    'analyzer_chunk_size' => \env('GITHOOKS_ANALYZER_CHUNK_SIZE', 100),
    'automatically_fix_errors' => \env('GITHOOKS_AUTOMATICALLY_FIX_ERRORS', true),
    'rerun_analyzer_after_autofix' => \env('GITHOOKS_RERUN_ANALYZER_AFTER_AUTOFIX', true),
    'stop_at_first_analyzer_failure' => \env('GITHOOKS_STOP_AT_FIRST_ANALYZER_FAILURE', true),
    'output_errors' => \env('GITHOOKS_OUTPUT_ERRORS', false),
    'debug_commands' => \env('GITHOOKS_DEBUG_COMMANDS', false),
    'debug_output' => \env('GITHOOKS_DEBUG_OUTPUT', false),
];
