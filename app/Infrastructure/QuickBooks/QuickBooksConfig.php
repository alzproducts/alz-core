<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use SensitiveParameter;

/**
 * Validated QBO connection settings, built only by QuickBooksConfigFactory.
 */
final readonly class QuickBooksConfig
{
    public const string AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';

    public const string TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const string REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const string SCOPE = 'com.intuit.quickbooks.accounting';

    // intentional: pinned so a bump is an explicit change; 1–74 are ignored by Intuit since 2025-08-01.
    public const string MINOR_VERSION = '75';

    public function __construct(
        public QuickBooksEnvironment $environment,
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri,
        public string $realmId,
    ) {}
}
