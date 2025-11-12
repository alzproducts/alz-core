<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only validate in production to avoid disrupting local development and CI
        // Skip validation in CI environments (GitHub Actions, etc.)
        $isCi = \getenv('CI') !== false || \getenv('GITHUB_ACTIONS') !== false;

        if ($this->app->environment('production') && ! $isCi) {
            $this->validateProductionEnvironment();
        }
    }

    /**
     * Validate that all required environment variables are configured in production.
     *
     * Prevents catastrophic deployment failures due to missing secrets.
     */
    private function validateProductionEnvironment(): void
    {
        // Map of config keys to human-readable descriptions
        $required = [
            'app.key' => 'Application encryption key (APP_KEY)',
            'database.connections.pgsql.host' => 'Database host (DB_HOST)',
            'database.connections.pgsql.password' => 'Database password (DB_PASSWORD)',
            'database.redis.default.host' => 'Redis host (REDIS_HOST)',
            'database.redis.default.password' => 'Redis password (REDIS_PASSWORD)',
            'horizon.auth.username' => 'Horizon dashboard username (HORIZON_USER)',
            'horizon.auth.password' => 'Horizon dashboard password (HORIZON_PASSWORD)',
            'services.supabase.jwt_secret' => 'Supabase JWT secret (SUPABASE_JWT_SECRET)',
        ];

        $missing = [];

        foreach ($required as $configKey => $description) {
            $value = \config($configKey);

            if ($value === null || $value === '' || $value === false) {
                $missing[] = $description;
            }
        }

        if ($missing !== []) {
            $list = \implode("\n  - ", $missing);

            throw new RuntimeException(
                'SECURITY: Production deployment blocked. The following required '
                . "configuration values are not set:\n\n  - {$list}\n\n"
                . 'Application cannot start safely. Please configure these variables '
                . 'in your deployment environment.',
            );
        }

        // Additional validation: APP_KEY must be properly formatted (base64 encoded)
        $appKey = \config('app.key');
        if (! \is_string($appKey) || \mb_strlen($appKey) < 32) {
            throw new RuntimeException(
                'SECURITY: APP_KEY is too short or invalid. '
                . 'Run \'php artisan key:generate\' to create a secure key.',
            );
        }
    }
}
