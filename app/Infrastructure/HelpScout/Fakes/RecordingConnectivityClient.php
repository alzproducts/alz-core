<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Fakes;

use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingConnectivityClient implements ConnectivityClientInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function verifyConnectivity(): void
    {
        $this->recorder->record(LiveIntegration::HelpScout, 'verifyConnectivity');
    }
}
