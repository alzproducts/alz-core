<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Fakes;

use App\Application\Contracts\ReviewsIoClientInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedReviewsIoClient implements ReviewsIoClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function getProductRatingBatch(array|string $skus): array
    {
        throw $this->gate->failClosed(LiveIntegration::ReviewsIo);
    }

    public function acceptsSku(string $sku): bool
    {
        throw $this->gate->failClosed(LiveIntegration::ReviewsIo);
    }

    public function verifyConnectivity(): void
    {
        throw $this->gate->failClosed(LiveIntegration::ReviewsIo);
    }
}
