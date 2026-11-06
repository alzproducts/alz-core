<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\LoadsConfigUnderEnv;
use Tests\TestCase;

/**
 * A local or testing app sends nothing to Sentry, whatever SENTRY_LARAVEL_DSN holds, unless SENTRY_LIVE opts in (AC9).
 */
final class SentryLiveOptInTest extends TestCase
{
    use LoadsConfigUnderEnv;

    private const string DSN = 'https://public@o0.ingest.sentry.io/0';

    private const array ENV_KEYS = ['APP_ENV', 'SENTRY_LARAVEL_DSN', 'SENTRY_LIVE', 'SENTRY_ENVIRONMENT'];

    /**
     * @return iterable<string, array{array<string, string>, ?string}>
     */
    public static function environments(): iterable
    {
        yield 'local without the opt-in' => [['APP_ENV' => 'local'], null];
        yield 'testing without the opt-in' => [['APP_ENV' => 'testing'], null];
        yield 'local with an unparseable opt-in' => [['APP_ENV' => 'local', 'SENTRY_LIVE' => 'yes please'], null];
        yield 'local with the opt-in' => [['APP_ENV' => 'local', 'SENTRY_LIVE' => 'true'], self::DSN];
        yield 'testing with the opt-in' => [['APP_ENV' => 'testing', 'SENTRY_LIVE' => 'true'], self::DSN];
        yield 'production' => [['APP_ENV' => 'production'], self::DSN];
        yield 'staging' => [['APP_ENV' => 'staging'], self::DSN];
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('environments')]
    public function the_dsn_reaches_sentry_only_when_live(array $env, ?string $expectedDsn): void
    {
        /** @var array{dsn: mixed, environment: mixed} $config */
        $config = self::loadConfigUnder('sentry.php', self::ENV_KEYS, [...$env, 'SENTRY_LARAVEL_DSN' => self::DSN]);

        $this->assertSame($expectedDsn, $config['dsn']);
        $this->assertSame($env['APP_ENV'], $config['environment']);
    }
}
