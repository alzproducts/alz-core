<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\StandIns;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Infrastructure\Integrations\StandInCallLogger;

/**
 * intentional: supports() and extractClickId() mirror the real Google and Bing adapters,
 * so local runs create the same per-platform conversion actions production would.
 */
final readonly class StandInAdPlatformConversionAdapter implements AdPlatformConversionAdapterInterface
{
    public function __construct(
        private AdPlatform $platform,
        private StandInCallLogger $logger,
    ) {}

    public function platform(): AdPlatform
    {
        return $this->platform;
    }

    public function supports(ConversionType $type): bool
    {
        return match ($this->platform) {
            AdPlatform::Google => true,
            AdPlatform::Bing => $type === ConversionType::LeadReceived,
        };
    }

    public function extractClickId(MarketingAttribution $attribution): ?string
    {
        return match ($this->platform) {
            AdPlatform::Google => $attribution->gclid,
            AdPlatform::Bing => $attribution->msclkid,
        };
    }

    public function upload(ConversionType $type, ConversionUploadDTO $data): void
    {
        $integration = match ($this->platform) {
            AdPlatform::Google => StandInCallLogger::GOOGLE_ADS,
            AdPlatform::Bing => StandInCallLogger::BING_ADS,
        };

        $this->logger->record($integration, 'upload', ['conversion_type' => $type->value]);
    }
}
