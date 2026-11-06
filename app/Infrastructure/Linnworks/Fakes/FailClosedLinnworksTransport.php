<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Fakes;

use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use Illuminate\Http\Client\Response;

final readonly class FailClosedLinnworksTransport implements LinnworksTransportInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function get(string $endpoint, array $query = []): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Linnworks);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Linnworks);
    }

    public function postJson(string $endpoint, array $data = []): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Linnworks);
    }

    public function postFormParams(string $endpoint, array $params = []): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Linnworks);
    }
}
