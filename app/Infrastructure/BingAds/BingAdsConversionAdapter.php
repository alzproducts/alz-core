<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\Msclkid;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Data\InvalidFormatException;

/**
 * Bing Ads adapter for the ad-platform conversion seam. Wraps
 * {@see BingAdsConversionService} and owns the msclkid extraction + validation.
 * Bing supports only lead conversions; quote uploads are rejected by {@see supports()}.
 */
final readonly class BingAdsConversionAdapter implements AdPlatformConversionAdapterInterface
{
    public function __construct(private BingAdsConversionService $service) {}

    public function platform(): AdPlatform
    {
        return AdPlatform::Bing;
    }

    public function supports(ConversionType $type): bool
    {
        return $type === ConversionType::LeadReceived;
    }

    public function extractClickId(MarketingAttribution $attribution): ?string
    {
        return $attribution->msclkid;
    }

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws UnsupportedConversionTypeException When Bing does not support the given ConversionType
     * @throws InvalidFormatException When the stored msclkid fails format validation
     */
    public function upload(ConversionType $type, ConversionUploadDTO $data): void
    {
        Msclkid::from($data->clickId);

        $this->service->uploadOfflineConversion($type, $data);
    }
}
