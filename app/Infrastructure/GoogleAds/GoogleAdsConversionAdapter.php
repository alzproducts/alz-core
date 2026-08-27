<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\Gclid;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Data\InvalidFormatException;

/**
 * Google Ads adapter for the ad-platform conversion seam. Wraps
 * {@see GoogleAdsConversionService} and owns the gclid extraction + validation.
 */
final readonly class GoogleAdsConversionAdapter implements AdPlatformConversionAdapterInterface
{
    public function __construct(private GoogleAdsConversionService $service) {}

    public function platform(): AdPlatform
    {
        return AdPlatform::Google;
    }

    public function supports(ConversionType $type): bool
    {
        return true;
    }

    public function extractClickId(MarketingAttribution $attribution): ?string
    {
        return $attribution->gclid;
    }

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidFormatException When the stored gclid fails format validation
     */
    public function upload(ConversionType $type, ConversionUploadDTO $data): void
    {
        Gclid::from($data->clickId);

        $this->service->uploadConversion($type, $data);
    }
}
