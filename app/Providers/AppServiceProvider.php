<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\ErrorReporting\SentryErrorReporter;
use App\Infrastructure\Integrations\IntegrationCredentials;
use App\Infrastructure\Sentry\SentryBeforeBreadcrumbCallback;
use App\Infrastructure\Sentry\SentryBeforeSendCallback;
use App\Infrastructure\Sentry\SentryBeforeSendTransactionCallback;
use Illuminate\Support\ServiceProvider;
use Override;
use Sentry\SentrySdk;

/**
 * Application Service Provider
 *
 * Three-layer validation pattern to prevent CI failures:
 *
 * 1. Constructor Validation - Service classes validate their own configuration
 *    (type safety, range checks, business rules). Keeps validation logic close
 *    to the service.
 *
 * 2. Lazy Resolution - register() uses lazy binding without eager validation.
 *    Only type-cast config values; validation happens when service is resolved.
 *    CRITICAL: register() runs during composer install/package:discover where
 *    secrets aren't available.
 *
 * 3. Production Boot - validateProductionEnvironment() checks critical credentials
 *    exist before app starts. Safety net for deployment failures.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(ErrorReporterInterface::class, SentryErrorReporter::class);

        // Set Sentry's hook callbacks before Sentry initializes
        // (must be in register(), not boot(), to run before Sentry's ServiceProvider)
        $this->app->booting(static function (): void {
            if (\class_exists(SentrySdk::class)) {
                \config([
                    'sentry.before_breadcrumb' => new SentryBeforeBreadcrumbCallback(),
                    'sentry.before_send' => new SentryBeforeSendCallback(),
                    'sentry.before_send_transaction' => new SentryBeforeSendTransactionCallback(),
                ]);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // intentional: before the CI skip and in every environment — staging and CI must refuse too.
        $this->refuseMfaSwitchOutsideLocal();

        // Skip validation in CI environments (GitHub Actions, etc.)
        $isCi = (\getenv('CI') !== false) || (\getenv('GITHUB_ACTIONS') !== false);

        if ($this->app->environment('production') && ! $isCi) {
            self::validateProductionEnvironment();
        }
    }

    /**
     * Validate that all required environment variables are configured in production.
     *
     * Prevents catastrophic deployment failures due to missing secrets.
     */
    /** @var array<string, string> Config keys → human-readable descriptions */
    private const array REQUIRED_PRODUCTION_CONFIGS = [
        'app.key' => 'Application encryption key (APP_KEY)',
        'call-tracking.default_business_phone_number' => 'Default business phone number (DEFAULT_BUSINESS_PHONE_NUMBER)',
        'database.connections.pgsql.host' => 'Database host (DB_HOST)',
        'database.connections.pgsql.password' => 'Database password (DB_PASSWORD)',
        'database.redis.default.host' => 'Redis host (REDIS_HOST)',
        'database.redis.default.password' => 'Redis password (REDIS_PASSWORD)',
        'horizon.auth.username' => 'Horizon dashboard username (HORIZON_USER)',
        'horizon.auth.password' => 'Horizon dashboard password (HORIZON_PASSWORD)',
        'services.supabase.url' => 'Supabase project URL (SUPABASE_URL)',
        'sentry.dsn' => 'Sentry DSN (SENTRY_LARAVEL_DSN)',
        ...IntegrationCredentials::ALL,
    ];

    private function refuseMfaSwitchOutsideLocal(): void
    {
        if ((\config('services.supabase.require_mfa') !== false) || $this->app->environment('local', 'testing')) {
            return;
        }

        throw new InvalidConfigurationException(
            'services.supabase.require_mfa',
            'SECURITY: SUPABASE_REQUIRE_MFA=false accepts password-only tokens and is refused outside the local and testing environments. Unset it or set it to true.',
        );
    }

    private static function validateProductionEnvironment(): void
    {
        self::ensureRequiredConfigsPresent();
        self::validateAppKeyFormat();
    }

    private static function ensureRequiredConfigsPresent(): void
    {
        $missing = [];

        foreach (self::REQUIRED_PRODUCTION_CONFIGS as $configKey => $description) {
            $value = \config($configKey);

            if (($value === null) || ($value === '') || ($value === false)) {
                $missing[] = $description;
            }
        }

        if ($missing !== []) {
            $list = \implode("\n  - ", $missing);

            throw new InvalidConfigurationException(
                'production.required',
                "SECURITY: Production deployment blocked. The following required configuration values are not set:\n\n  - {$list}\n\nApplication cannot start safely. Please configure these variables in your deployment environment.",
            );
        }
    }

    private static function validateAppKeyFormat(): void
    {
        $appKey = \config('app.key');

        if (! \is_string($appKey) || (\mb_strlen($appKey) < 32)) {
            throw new InvalidConfigurationException(
                'app.key',
                "SECURITY: APP_KEY is too short or invalid. Run 'php artisan key:generate' to create a secure key.",
            );
        }
    }
}
