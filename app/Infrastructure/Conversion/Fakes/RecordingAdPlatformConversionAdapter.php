<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\Fakes;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

/**
 * intentional: supports() and extractClickId() mirror the real Google and Bing adapters,
 * so local runs create the same per-platform conversion actions production would.
 */
final readonly class RecordingAdPlatformConversionAdapter implements AdPlatformConversionAdapterInterface
{
    public function __construct(
        private AdPlatform $platform,
        private OutboundCallRecorder $recorder,
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
            AdPlatform::Google => LiveIntegration::GoogleAds,
            AdPlatform::Bing => LiveIntegration::BingAds,
        };

        $this->recorder->record($integration, 'upload', ['conversion_type' => $type->value]);
    }
}
