<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\ApplyOfflineConversionsRequest;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\OfflineConversion;

/**
 * Thin client that wraps an {@see OfflineConversion} in an upload request
 * and delegates to {@see BingAdsConversionTransport}.
 */
final readonly class BingAdsConversionClient
{
    public function __construct(
        private BingAdsConversionTransport $transport,
        private BingAdsConfig $config,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     */
    public function uploadConversion(OfflineConversion $conversion): void
    {
        $request = new ApplyOfflineConversionsRequest([
            'AccountId' => $this->config->accountId,
            'OfflineConversions' => [$conversion],
        ]);

        $this->transport->applyOfflineConversion($request);
    }
}
