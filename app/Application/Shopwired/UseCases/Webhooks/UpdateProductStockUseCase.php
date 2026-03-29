<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\StockChangeDataDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
        private ShopwiredSyncDispatcherInterface $dispatcher,
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
    public function execute(WebhookContextDTO $context, StockChangeDataDTO $data): void
    {
        $logContext = ['webhook_id' => $context->webhookId, 'subject_id' => $data->productId->value, 'sku' => $data->sku->value];
        $this->logger->info('Processing product stock webhook', $logContext);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($context->eventTime < $cutoff) {
            $this->logger->info('Discarding stale product stock webhook', $logContext);

            return;
        }

        if ($this->idempotency->isSuperseded($data->productId, $context->topic, $context->webhookId)) {
            $this->logger->info('Discarding superseded product stock webhook', $logContext);

            return;
        }

        $this->productRepository->updateStock($data->sku, $data->isVariation, $data->newQuantity);
        $this->idempotency->record($data->productId, $context->topic, $context->webhookId, $context->eventTime);

        $this->dispatcher->dispatchProductSync($data->productId);

        $this->logger->info('Product stock webhook processed — sync queued', $logContext);
    }
}
