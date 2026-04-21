<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Dispatch the two full-catalogue refresh jobs (products + stock items).
 *
 * Deduplication is handled by ShouldBeUnique guards on the underlying jobs — a 202 means
 * "dispatch attempted", not "a new job is queued".
 */
final readonly class RefreshAllProductsUseCase
{
    public const int ESTIMATED_DURATION_SECONDS = 120;

    public function __construct(
        private ShopwiredSyncDispatcherInterface $shopwiredDispatcher,
        private LinnworksSyncDispatcherInterface $linnworksDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        $this->logger->info('Dispatching full product + stock catalogue refresh');

        $this->shopwiredDispatcher->dispatchAllProductsSync();
        $this->linnworksDispatcher->dispatchFullStockItemsSync();

        $this->logger->info('Full catalogue refresh dispatch complete');
    }
}
