<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Fails closed on any environment mix-up, so a non-production deployment can only reach the sandbox.
 */
final class QuickBooksConfigFactory
{
    /**
     * @throws InvalidConfigurationException
     */
    public static function create(): QuickBooksConfig
    {
        return new QuickBooksConfig(
            environment: self::environment(),
            clientId: self::requireStringConfig('quickbooks.client_id', 'QUICKBOOKS_CLIENT_ID'),
            clientSecret: self::requireStringConfig('quickbooks.client_secret', 'QUICKBOOKS_CLIENT_SECRET'),
            redirectUri: self::requireStringConfig('quickbooks.redirect_uri', 'QUICKBOOKS_REDIRECT_URI'),
            realmId: self::requireStringConfig('quickbooks.realm_id', 'QUICKBOOKS_REALM_ID'),
        );
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function environment(): QuickBooksEnvironment
    {
        // intentional: exact match only; a lenient fallback would send production keys to the sandbox.
        $environment = QuickBooksEnvironment::tryFrom(
            self::requireStringConfig('quickbooks.environment', 'QUICKBOOKS_ENVIRONMENT'),
        );

        if ($environment === null) {
            throw new InvalidConfigurationException('QUICKBOOKS_ENVIRONMENT', 'QuickBooks environment must be sandbox or production');
        }

        self::assertMatchesAppEnvironment($environment);

        return $environment;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertMatchesAppEnvironment(QuickBooksEnvironment $environment): void
    {
        $isProductionApp = \config('app.env') === 'production';

        if (($environment === QuickBooksEnvironment::Production) && !$isProductionApp) {
            throw new InvalidConfigurationException('QUICKBOOKS_ENVIRONMENT', 'QuickBooks production is refused outside production APP_ENV');
        }

        if (($environment === QuickBooksEnvironment::Sandbox) && $isProductionApp) {
            throw new InvalidConfigurationException('QUICKBOOKS_ENVIRONMENT', 'QuickBooks sandbox is refused under production APP_ENV');
        }
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function requireStringConfig(string $configKey, string $envVar): string
    {
        $value = \config($configKey);

        if (!\is_string($value) || ($value === '')) {
            throw new InvalidConfigurationException($envVar);
        }

        return $value;
    }
}
