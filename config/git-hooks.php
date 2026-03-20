<?php

declare(strict_types=1);

use App\DevTools\GitHooks\DeptracPrePushHook;
use App\DevTools\GitHooks\PestPrePushHook;
use App\DevTools\GitHooks\PHPArkitectPreCommitHook;
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
    | Runs full test suite + layer dependency checks (~15 seconds with native PHP)
    | All tests use mocks/fakes - no external dependencies
    |
    | Coverage checks: Run `make test-coverage` manually before PRs
    | Mutation testing moved to CI (runs in parallel, no added wall time)
    */
    'pre-push' => [
        PestPrePushHook::class,
        DeptracPrePushHook::class,
        TLintPrePushHook::class,
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
            // Exclude tests/ and database/migrations/ from pre-commit hook
            // (both are excluded in phpstan.neon excludePaths, but that only works for full-codebase runs)
            // Regex matches .php files but rejects paths starting with tests/, database/migrations/, or containing /tests/
            'file_extensions' => env('LARASTAN_FILE_EXTENSIONS', '/^(?!tests\/)(?!.*\/tests\/)(?!database\/migrations\/).*\.php$/'),
            'run_in_docker' => env('LARASTAN_RUN_IN_DOCKER', false),
            'docker_container' => env('LARASTAN_DOCKER_CONTAINER', ''),
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
