<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\InvalidConfigurationException;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\SdkException;
use SensitiveParameter;

/**
 * Builds a DataService per access token. The SDK defaults are unsafe (ledger P2, P11), so every setting here is load-bearing.
 */
final readonly class QuickBooksDataServiceFactory
{
    private const string CLIENT_NAME = 'guzzle';

    public function __construct(
        private QuickBooksConfig $config,
    ) {}

    public function create(#[SensitiveParameter] ApiKeyToken $accessToken): DataService
    {
        $dataService = $this->configure($accessToken);
        $dataService->setClientName(self::CLIENT_NAME);
        $dataService->throwExceptionOnError(true);
        $dataService->setMinorVersion(QuickBooksConfig::MINOR_VERSION);
        // intentional: last — setClientName and setMinorVersion both rebuild the rest handler and its logger, re-enabling logging (ledger P11).
        $dataService->disableLog();

        return $dataService;
    }

    private function configure(#[SensitiveParameter] ApiKeyToken $accessToken): DataService
    {
        try {
            return DataService::Configure([
                'auth_mode' => 'oauth2',
                'ClientID' => $this->config->clientId,
                'ClientSecret' => $this->config->clientSecret,
                'accessTokenKey' => $accessToken->value,
                'QBORealmID' => $this->config->realmId,
                'baseUrl' => $this->config->environment->apiBaseUrl(),
            ]);
        } catch (SdkException $e) {
            // The SDK's Configure messages are static strings with no settings values in them.
            throw new InvalidConfigurationException('QUICKBOOKS_*', $e->getMessage(), $e);
        }
    }
}
