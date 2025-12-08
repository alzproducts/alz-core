<?php

declare(strict_types=1);

use App\Infrastructure\Mixpanel\MixpanelAdSpendEventDTO;
use App\Presentation\Console\Commands\SyncAdSpendCommand;

/**
 * Architecture Tests using Pest 4.
 *
 * These tests enforce codebase-wide standards:
 * - PHP best practices (no debug functions, deprecated APIs, suspicious chars)
 * - Security checks (no eval, no md5 for passwords)
 *
 * Note: Laravel preset skipped - it expects standard Laravel directory structure
 * (App\Console\Commands, App\Http\Controllers) which conflicts with Clean Architecture
 * (App\Presentation\Console\Commands, App\Presentation\Http\Controllers).
 */

// PHP preset - bans debug functions, deprecated APIs, suspicious Unicode chars
// Ignore: Console commands legitimately use echo for CLI output
arch()->preset()->php()
    ->ignoring(SyncAdSpendCommand::class);

// Security preset - bans eval, insecure hashing for passwords
// Ignore: MixpanelAdSpendEventDTO uses md5 for ID generation, not password hashing
arch()->preset()->security()
    ->ignoring(MixpanelAdSpendEventDTO::class);
