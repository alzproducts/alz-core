<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;

/**
 * Thin client that wraps a {@see ClickConversion} in an upload request
 * and delegates to {@see GoogleAdsTransport}.
 */
final readonly class GoogleAdsConversionClient
{
    public function __construct(
        private GoogleAdsTransport $transport,
        private GoogleAdsConfig $config,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     */
    public function uploadConversion(ClickConversion $conversion): void
    {
        $request = new UploadClickConversionsRequest();
        $request->setCustomerId($this->config->customerId);
        $request->setConversions([$conversion]);
        $request->setPartialFailure(true);

        $this->transport->uploadClickConversion($request);
    }
}
