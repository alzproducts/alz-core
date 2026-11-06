<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Fakes;

use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\PoolPostResult;
use App\Infrastructure\Shopwired\RetryStrategy;
use Illuminate\Http\Client\Response;

final readonly class FailClosedShopwiredTransport implements ShopwiredTransportInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function get(string $endpoint, array $query = [], bool $retry = true, RetryStrategy $strategy = RetryStrategy::Background): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Shopwired);
    }

    public function post(string $endpoint, array $data = [], bool $retry = true, RetryStrategy $strategy = RetryStrategy::Background): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Shopwired);
    }

    public function put(string $endpoint, array $data = [], bool $retry = true, RetryStrategy $strategy = RetryStrategy::Background): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Shopwired);
    }

    public function getResource(string $resourceType, int|string $id, string $endpoint, array $query = []): Response
    {
        throw $this->gate->failClosed(LiveIntegration::Shopwired);
    }

    public function poolPost(array $requests): PoolPostResult
    {
        throw $this->gate->failClosed(LiveIntegration::Shopwired);
    }
}
