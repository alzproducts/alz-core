<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Scan all products for sale state drift and dispatch per-product reconciliation.
 *
 * Used as a scheduled safety net — catches drift missed by the per-update reconciler
 * (e.g., manual changes in ShopWired admin, webhook failures).
 */
final readonly class ReconcileBulkSaleStateUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private SaleReconciliationDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private int $saleCategoryId,
    ) {}

    /**
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function execute(): void
    {
        $driftedIds = $this->productRepo->getAllProductsWithSaleStateDrift($this->saleCategoryId);
        $count = \count($driftedIds);

        foreach ($driftedIds as $id) {
            $this->dispatcher->dispatchReconciliation(IntId::fromTrusted($id));
        }

        $this->logger->info('Bulk sale state reconciliation completed', [
            'dispatched' => $count,
        ]);
    }
}
