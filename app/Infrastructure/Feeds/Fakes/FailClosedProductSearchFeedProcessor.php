<?php

declare(strict_types=1);

namespace App\Infrastructure\Feeds\Fakes;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedProductSearchFeedProcessor implements ProductSearchFeedProcessorInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function process(string $sourceUrl, string $outputPath): ProductSearchFeedProcessingResult
    {
        throw $this->gate->failClosed(LiveIntegration::Doofinder);
    }
}
