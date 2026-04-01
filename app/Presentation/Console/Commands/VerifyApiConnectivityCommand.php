<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\BingAdsClientInterface;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface as HelpScoutConnectivityClient;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface as LinnworksConnectivityClient;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface as ShopwiredConnectivityClient;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
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
        {client : The API client to verify (reviewsio, mixpanel, googleads, shopwired, linnworks, bingads, helpscout, all)}';

    protected $description = 'Verify connectivity and authentication with external API services';

    public function handle(): int
    {
        /** @var string $client */
        $client = $this->argument('client');
        $results = $this->resolveVerifications($client);

        if ($results === null) {
            $this->error("Unknown client: {$client}");
            $this->line('Available: reviewsio, mixpanel, googleads, bingads, shopwired, linnworks, helpscout, all');

            return self::FAILURE;
        }

        return $this->displayResults($results);
    }

    /**
     * @return array<string, bool>|null
     */
    private function resolveVerifications(string $client): ?array
    {
        return match ($client) {
            'reviewsio' => ['reviewsio' => $this->verifyReviewsIo()],
            'mixpanel' => ['mixpanel' => $this->verifyMixpanel()],
            'googleads' => ['googleads' => $this->verifyGoogleAds()],
            'bingads' => ['bingads' => $this->verifyBingAds()],
            'shopwired' => ['shopwired' => $this->verifyShopwired()],
            'linnworks' => ['linnworks' => $this->verifyLinnworks()],
            'helpscout' => ['helpscout' => $this->verifyHelpScout()],
            'all' => $this->verifyAll(),
            default => null,
        };
    }

    /**
     * @return array<string, bool>
     */
    private function verifyAll(): array
    {
        return [
            'reviewsio' => $this->verifyReviewsIo(),
            'mixpanel' => $this->verifyMixpanel(),
            'googleads' => $this->verifyGoogleAds(),
            'bingads' => $this->verifyBingAds(),
            'shopwired' => $this->verifyShopwired(),
            'linnworks' => $this->verifyLinnworks(),
            'helpscout' => $this->verifyHelpScout(),
        ];
    }

    /**
     * @param array<string, bool> $results
     */
    private function displayResults(array $results): int
    {
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
     * Format an exception message with structured context for operator debugging.
     */
    private static function formatError(Throwable $e): string
    {
        $message = $e->getMessage();
        if (\method_exists($e, 'context')) {
            $ctx = $e->context();
            if ($ctx !== []) {
                $message .= ' — ' . \json_encode($ctx);
            }
        }

        return $message;
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
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('  Failed: ' . self::formatError($e));
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
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('  Failed: ' . self::formatError($e));
            $this->line('  Check: MIXPANEL_* credentials in .env');

            return false;
        }
    }

    private function verifyGoogleAds(): bool
    {
        $this->info('Verifying Google Ads...');

        try {
            \app(GoogleAdsClientInterface::class)->verifyConnectivity();
            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (AuthenticationExpiredException $e) {
            return $this->displayAuthFailure($e, 'Developer token access level in Google Ads API Center');
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            return $this->displayConnectivityFailure($e, 'Google Ads OAuth credentials and refresh token');
        }
    }

    private function verifyBingAds(): bool
    {
        $this->info('Verifying Bing Ads...');

        try {
            \app(BingAdsClientInterface::class)->verifyConnectivity();
            $this->line('  Authentication: OK');
            $this->line('  Currency: GBP ✓');

            return true;
        } catch (AuthenticationExpiredException $e) {
            return $this->displayAuthFailure($e, 'Azure AD app permissions and OAuth credentials');
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            return $this->displayConnectivityFailure($e, 'BING_ADS_* credentials in .env');
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
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('  Failed: ' . self::formatError($e));
            $this->line('  Check: SHOPWIRED_API_KEY and SHOPWIRED_API_SECRET in .env');

            return false;
        }
    }

    private function verifyLinnworks(): bool
    {
        $this->info('Verifying Linnworks...');

        try {
            $client = \app(LinnworksConnectivityClient::class);
            $client->verifyConnectivity();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('  Failed: ' . self::formatError($e));
            $this->line('  Check: LINNWORKS_APPLICATION_ID, LINNWORKS_APPLICATION_SECRET, and LINNWORKS_INSTALLATION_TOKEN in .env');

            return false;
        }
    }

    private function verifyHelpScout(): bool
    {
        $this->info('Verifying HelpScout...');

        try {
            \app(HelpScoutConnectivityClient::class)->verifyConnectivity();
            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');

            return true;
        } catch (AuthenticationExpiredException $e) {
            return $this->displayAuthFailure($e, 'HELPSCOUT_APP_ID and HELPSCOUT_APP_SECRET in .env');
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            return $this->displayConnectivityFailure($e, 'HELPSCOUT_APP_ID and HELPSCOUT_APP_SECRET in .env');
        }
    }

    private function displayAuthFailure(AuthenticationExpiredException $e, string $hint): false
    {
        $this->error('  Authorization Failed: ' . self::formatError($e));
        $this->line("  Check: {$hint}");

        return false;
    }

    private function displayConnectivityFailure(Throwable $e, string $hint): false
    {
        $this->error('  Failed: ' . self::formatError($e));
        $this->line("  Check: {$hint}");

        return false;
    }
}
