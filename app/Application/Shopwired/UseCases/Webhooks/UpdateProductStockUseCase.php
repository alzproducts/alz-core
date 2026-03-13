<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredProductJob;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `product.stock_changed` webhook events.
 *
 * Applies staleness and idempotency guards, updates stock quantity by SKU,
 * records webhook timestamp, then queues a full API sync.
 */
final readonly class UpdateProductStockUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        DateTimeImmutable $eventTime,
        int $webhookId,
        IntId $productId,
        Sku $sku,
        bool $isVariation,
        int $newQuantity,
    ): void {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $productId->value, 'sku' => $sku->value];
        $this->logger->info('Processing product stock webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale product stock webhook', $context);

            return;
        }

        $existing = $this->productRepository->getWebhookTimestamp($productId);

        if ($existing !== null && $existing >= $eventTime) {
            $this->logger->info('Discarding superseded product stock webhook', $context);

            return;
        }

        $this->productRepository->updateStock($sku, $isVariation, $newQuantity);
        $this->productRepository->updateWebhookTimestamp($productId, $eventTime);

        SyncShopwiredProductJob::dispatch($productId);

        $this->logger->info('Product stock webhook processed — sync queued', $context);
    }
}
