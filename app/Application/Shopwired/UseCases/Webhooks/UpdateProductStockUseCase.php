<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredProductJob;
use App\Application\Shopwired\Enums\WebhookTopic;
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
 * records the webhook event, then queues a full API sync.
 */
final readonly class UpdateProductStockUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private WebhookIdempotencyServiceInterface $idempotency,
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
        WebhookTopic $topic,
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

        if ($this->idempotency->isSuperseded($productId, $topic, $webhookId)) {
            $this->logger->info('Discarding superseded product stock webhook', $context);

            return;
        }

        $this->productRepository->updateStock($sku, $isVariation, $newQuantity);
        $this->idempotency->record($productId, $topic, $webhookId, $eventTime);

        SyncShopwiredProductJob::dispatch($productId);

        $this->logger->info('Product stock webhook processed — sync queued', $context);
    }
}
