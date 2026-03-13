<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredProductJob;
use App\Application\Shopwired\Enums\WebhookTopic;
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
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Product $product, array $presentEmbeds = []): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $product->id];
        $this->logger->info('Processing product webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale product webhook', $context);

            return;
        }

        $productId = IntId::from($product->id);

        if ($this->idempotency->isSuperseded($productId, $topic, $webhookId)) {
            $this->logger->info('Discarding already-processed product webhook', $context);

            return;
        }

        $this->productRepository->saveFromWebhook($product, $presentEmbeds);
        $this->idempotency->record($productId, $topic, $webhookId, $eventTime);

        SyncShopwiredProductJob::dispatch($productId);

        $this->logger->info('Product webhook processed — sync queued', $context);
    }
}
