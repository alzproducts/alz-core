<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface as ShopwiredConnectivityClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verify connectivity and authentication with external API services.
 *
 * This command provides a quick way to validate that all API credentials
 * are correctly configured and that services are accessible. Useful for:
 * - Post-deployment verification
 * - Debugging connectivity issues
 * - CI/CD health checks
 */
final class VerifyApiConnectivityCommand extends Command
{
    protected $signature = 'verify:api
        {client : The API client to verify (reviewsio, mixpanel, googleads, shopwired, all)}';

    protected $description = 'Verify connectivity and authentication with external API services';

    public function handle(): int
    {
        /** @var string $client Required argument, guaranteed by Laravel */
        $client = $this->argument('client');

        $results = match ($client) {
            'reviewsio' => ['reviewsio' => $this->verifyReviewsIo()],
            'mixpanel' => ['mixpanel' => $this->verifyMixpanel()],
            'googleads' => ['googleads' => $this->verifyGoogleAds()],
            'shopwired' => ['shopwired' => $this->verifyShopwired()],
            'all' => [
                'reviewsio' => $this->verifyReviewsIo(),
                'mixpanel' => $this->verifyMixpanel(),
                'googleads' => $this->verifyGoogleAds(),
                'shopwired' => $this->verifyShopwired(),
            ],
            default => null,
        };

        if ($results === null) {
            $this->error("Unknown client: {$client}");
            $this->line('Available: reviewsio, mixpanel, googleads, shopwired, all');

            return self::FAILURE;
        }

        $this->newLine();
        $failed = \array_filter($results, static fn(bool $success): bool => ! $success);

        if ($failed === []) {
            $this->info('All API clients verified successfully');

            return self::SUCCESS;
        }

        $this->error('Some API clients failed: ' . \implode(', ', \array_keys($failed)));

        return self::FAILURE;
    }

    /**
     * Verify Reviews.io API connectivity.
     */
    private function verifyReviewsIo(): bool
    {
        $this->info('Verifying Reviews.io...');

        try {
            $client = \app(ReviewsIoClientInterface::class);
            $client->verifyConnectivity();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: REVIEWSIO_API_KEY and REVIEWSIO_STORE in .env');

            return false;
        }
    }

    private function verifyMixpanel(): bool
    {
        $this->info('Verifying Mixpanel...');

        try {
            $client = \app(MixpanelClientInterface::class);
            // Calls /api/app/me to validate service account credentials
            $client->verifyConnectivity();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: MIXPANEL_* credentials in .env');

            return false;
        }
    }

    private function verifyGoogleAds(): bool
    {
        $this->info('Verifying Google Ads...');

        try {
            $client = \app(GoogleAdsClientInterface::class);
            $client->verifyConnectivity();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: Google Ads OAuth credentials and refresh token');

            return false;
        }
    }

    private function verifyShopwired(): bool
    {
        $this->info('Verifying Shopwired...');

        try {
            $client = \app(ShopwiredConnectivityClient::class);
            $client->verifyConnectivity();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: SHOPWIRED_API_KEY and SHOPWIRED_API_SECRET in .env');

            return false;
        }
    }
}
