<?php

declare(strict_types=1);

use App\DevTools\GitHooks\InfectionPrePushHook;
use App\DevTools\GitHooks\PestMutatePrePushHook;
use App\DevTools\GitHooks\PestPrePushHook;
use App\DevTools\GitHooks\PHPArkitectPreCommitHook;
use App\DevTools\GitHooks\PHPArkitectPrePushHook;
use App\DevTools\GitHooks\PHPInsightsPrePushHook;
use App\DevTools\GitHooks\TLintPrePushHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\LarastanPreCommitHook;
use Igorsgm\GitHooks\Console\Commands\Hooks\PintPreCommitHook;

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-Commit Hooks
    |--------------------------------------------------------------------------
    | Fast linting checks on staged files - runs before commit (5-10 seconds)
    | PHPArkitect is extremely fast (~0.1s) so included for immediate feedback
    */
    'pre-commit' => [
        PintPreCommitHook::class,
        LarastanPreCommitHook::class,
        PHPArkitectPreCommitHook::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-Push Hooks
    |--------------------------------------------------------------------------
    | Runs unit tests + code quality analysis (~15 seconds with native PHP)
    | Integration/feature tests run in CI only (avoids flaky network deps)
    |
    | Mutation testing moved to CI (runs in parallel, no added wall time)
    */
    'pre-push' => [
        PestPrePushHook::class,
        PHPInsightsPrePushHook::class,
        PHPArkitectPrePushHook::class,
        TLintPrePushHook::class,
        // PestMutatePrePushHook::class,  // Moved to CI - runs in parallel
        // InfectionPrePushHook::class,   // Moved to CI - runs in parallel
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
    | Disabled by default: native PHP 8.4 is 2-3x faster for git hooks
    */
    'use_sail' => env('GITHOOKS_USE_SAIL', false),

    /*
    |--------------------------------------------------------------------------
    | Configuration (Using defaults from vendor config)
    |--------------------------------------------------------------------------
    */
    'code_analyzers' => [
        'laravel_pint' => [
            'path' => env('LARAVEL_PINT_PATH', 'vendor/bin/pint'),
            'config' => env('LARAVEL_PINT_CONFIG', 'pint.json'),
            'preset' => env('LARAVEL_PINT_PRESET'),
            'file_extensions' => env('LARAVEL_PINT_FILE_EXTENSIONS', '/\.php$/'),
            'run_in_docker' => env('LARAVEL_PINT_RUN_IN_DOCKER', false),
            'docker_container' => env('LARAVEL_PINT_DOCKER_CONTAINER', ''),
        ],
        'larastan' => [
            'path' => env('LARASTAN_PATH', 'vendor/bin/phpstan'),
            'config' => env('LARASTAN_CONFIG', 'phpstan.neon'),
            'additional_params' => env('LARASTAN_ADDITIONAL_PARAMS', ''),
            // Exclude tests/ directory from pre-commit hook (tests excluded from phpstan.neon paths)
            // Regex matches .php files but rejects paths starting with tests/ or containing /tests/
            'file_extensions' => env('LARASTAN_FILE_EXTENSIONS', '/^(?!tests\/)(?!.*\/tests\/).*\.php$/'),
            'run_in_docker' => env('LARASTAN_RUN_IN_DOCKER', false),
            'docker_container' => env('LARASTAN_DOCKER_CONTAINER', ''),
        ],
        'phpinsights' => [
            'path' => env('PHPINSIGHTS_PATH', 'vendor/bin/phpinsights'),
            'config' => env('PHPINSIGHTS_CONFIG', 'config/insights.php'),
            'file_extensions' => env('PHPINSIGHTS_FILE_EXTENSIONS', '/\.php$/'),
            'additional_params' => env('PHPINSIGHTS_ADDITIONAL_PARAMS', ''),
            'run_in_docker' => env('PHPINSIGHTS_RUN_IN_DOCKER', false),
            'docker_container' => env('PHPINSIGHTS_DOCKER_CONTAINER', ''),
        ],
    ],

    'artisan_path' => base_path('artisan'),
    'validate_paths' => env('GITHOOKS_VALIDATE_PATHS', true),
    'analyzer_chunk_size' => env('GITHOOKS_ANALYZER_CHUNK_SIZE', 100),
    'automatically_fix_errors' => env('GITHOOKS_AUTOMATICALLY_FIX_ERRORS', true),
    'rerun_analyzer_after_autofix' => env('GITHOOKS_RERUN_ANALYZER_AFTER_AUTOFIX', true),
    'stop_at_first_analyzer_failure' => env('GITHOOKS_STOP_AT_FIRST_ANALYZER_FAILURE', true),
    'output_errors' => env('GITHOOKS_OUTPUT_ERRORS', false),
    'debug_commands' => env('GITHOOKS_DEBUG_COMMANDS', false),
    'debug_output' => env('GITHOOKS_DEBUG_OUTPUT', false),
];
