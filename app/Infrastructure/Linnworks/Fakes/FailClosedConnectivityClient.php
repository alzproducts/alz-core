<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Fakes;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedConnectivityClient implements ConnectivityClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function verifyConnectivity(): void
    {
        throw $this->gate->failClosed(LiveIntegration::Linnworks);
    }
}
