<?php

declare(strict_types=1);

use App\Infrastructure\Mixpanel\MixpanelAdSpendEventDTO;

/**
 * Architecture Tests using Pest 4.
 *
 * These tests enforce codebase-wide standards:
 * - Security checks (no eval, no debug functions)2
 * - Homograph attack detection (suspicious Unicode characters)
 * - PHP best practices (no die/dump/dd/var_dump in production code)
 *
 * Note: We skip arch()->preset()->php() because it scans vendor dependencies
 * and triggers deprecation notices from third-party packages (Google Ads SDK).
 * PHPStan at level max already catches most PHP issues.
 *
 * Note: Laravel preset skipped - it conflicts with Clean Architecture
 * (expects app/Console/Commands, we use Presentation/Console/Commands)
 */
// Security preset with exception for ID hashing (not password hashing)
arch()->preset()->security()
    ->ignoring(MixpanelAdSpendEventDTO::class);

// PHP best practices - targeted rules without vendor scanning
arch('no debug functions in production code')
    ->expect('App')
    ->not->toUse(['die', 'dd', 'dump', 'var_dump', 'print_r', 'exit']);

arch('no suspicious characters in code')
    ->expect('App')
    ->not->toHaveSuspiciousCharacters();
