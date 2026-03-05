<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Jobs\Shopwired\ReconcileShopwiredProductJob;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `product.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the product from the webhook
 * payload with its timestamp, then queues a full API reconciliation.
 */
final readonly class SyncProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, Product $product): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $product->id];

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale product webhook', $context);

            return;
        }

        $productId = IntId::from($product->id);
        $existing = $this->productRepository->getWebhookTimestamp($productId);

        if ($existing !== null && $existing >= $eventTime) {
            $this->logger->info('Discarding already-processed product webhook', $context);

            return;
        }

        $this->productRepository->saveFromWebhook($product, $eventTime);

        ReconcileShopwiredProductJob::dispatch($productId);

        $this->logger->info('Product webhook processed — reconciliation queued', $context);
    }
}
