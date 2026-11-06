<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds\Fakes;

use App\Application\Contracts\Conversion\GoogleAdsConversionProbeInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedGoogleAdsConversionProbe implements GoogleAdsConversionProbeInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function probeUpload(): void
    {
        throw $this->gate->failClosed(LiveIntegration::GoogleAds);
    }
}
