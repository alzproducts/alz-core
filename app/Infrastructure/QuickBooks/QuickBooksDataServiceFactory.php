<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\InvalidConfigurationException;
use QuickBooksOnline\API\Core\CoreConstants;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\SdkException;
use SensitiveParameter;

/**
 * Builds a DataService per access token. The SDK defaults are unsafe (ledger P2, P11), so every setting here is load-bearing.
 * Never reconfigure a built DataService: `setClientName`, `setMinorVersion`, `useXml`/`useJson` and `updateOAuth2Token`
 * rebuild the rest handler, dropping the hardened client and turning SDK logging back on.
 */
final readonly class QuickBooksDataServiceFactory
{
    public function __construct(
        private QuickBooksConfig $config,
    ) {}

    /**
     * @throws InvalidConfigurationException when the SDK settings or the client seam fail
     */
    public function create(#[SensitiveParameter] ApiKeyToken $accessToken, int $timeoutSeconds): QuickBooksDataServiceSession
    {
        $httpClient = new QuickBooksCurlHttpClient($timeoutSeconds);
        $dataService = $this->configure($accessToken);
        $dataService->setClientName(CoreConstants::CLIENT_CURL);
        // intentional: off, so a Fault comes back through FaultHandler instead of a ServiceException holding the whole body.
        $dataService->throwExceptionOnError(false);
        $dataService->setMinorVersion(QuickBooksConfig::MINOR_VERSION);
        QuickBooksRestHandlerSeam::install($dataService, $httpClient);
        // intentional: last — the client name, minor version and seam each replace the rest handler and its logger, re-enabling logging (ledger P11).
        $dataService->disableLog();

        return new QuickBooksDataServiceSession($dataService, $httpClient);
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
