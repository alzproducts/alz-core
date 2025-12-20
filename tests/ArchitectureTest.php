<?php

declare(strict_types=1);

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
 *
 * TEMPORARILY DISABLED: Google Ads SDK triggers PHP 8.4 deprecation warnings during
 * architecture scanning (implicit nullable parameters). Pest 4 catches these during
 * class autoloading and marks tests as "deprecated", causing exit code 1 even with
 * failOnDeprecation="false" in phpunit.xml. The --do-not-fail-on-deprecation CLI flag
 * doesn't help because PHPUnit's error handler isn't active during autoload.
 *
 * @see todo.php for automated reminder when SDK is upgraded
 * @see https://github.com/googleads/google-ads-php/issues/1056
 */

//test('architecture security preset')->skip(
//    'Google Ads SDK PHP 8.4 deprecation - see todo.php (googleads/google-ads-php:>31.1.0)'
//);

// Original test - re-enable when Google Ads SDK is fixed:
// arch()->preset()->security()
//     ->ignoring(MixpanelAdSpendEventDTO::class)  // md5 for ID generation, not passwords
//     ->ignoring(BingAdsTransport::class);        // tempnam for ZIP extraction
