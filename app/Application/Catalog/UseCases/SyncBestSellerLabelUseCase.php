<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelChangesResult;
use App\Application\Catalog\BestSellerLabels\BestSellerLabelTransformer;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncBestSellerLabelUseCase
{
    public function __construct(
        private ProductViewQueryRepositoryInterface $productViewQueryRepo,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SyncBestSellerLabel: checking for label drift');

        $changes = $this->productViewQueryRepo->findBestSellerLabelChanges();

        if (! $changes->hasChanges()) {
            $this->logger->info('SyncBestSellerLabel: no label changes needed');

            return;
        }

        $this->dispatchChanges($changes);

        $this->logger->info('SyncBestSellerLabel: dispatched label updates', [
            'dispatched_add' => \count($changes->toAdd),
            'dispatched_remove' => \count($changes->toRemove),
        ]);
    }

    private function dispatchChanges(BestSellerLabelChangesResult $changes): void
    {
        foreach ($changes->toAdd as $candidate) {
            $targetLabels = BestSellerLabelTransformer::addLabel($candidate->currentLabels);
            $this->dispatcher->dispatchBestSellerLabelUpdate($candidate->productId, $targetLabels);
        }

        foreach ($changes->toRemove as $candidate) {
            $targetLabels = BestSellerLabelTransformer::removeLabel($candidate->currentLabels);
            $this->dispatcher->dispatchBestSellerLabelUpdate($candidate->productId, $targetLabels);
        }
    }
}
