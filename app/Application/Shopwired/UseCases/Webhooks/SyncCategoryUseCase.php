<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredCategoryJob;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `category.created` / `category.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the category from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncCategoryUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
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
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Category $category, array $presentEmbeds = []): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $category->id];
        $this->logger->info('Processing category webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale category webhook', $context);

            return;
        }

        $categoryId = IntId::from($category->id);

        if ($this->idempotency->isSuperseded($categoryId, $topic, $webhookId)) {
            $this->logger->info('Discarding already-processed category webhook', $context);

            return;
        }

        $this->categoryRepository->saveFromWebhook($category, $presentEmbeds);
        $this->idempotency->record($categoryId, $topic, $webhookId, $eventTime);

        SyncShopwiredCategoryJob::dispatch($categoryId);

        $this->logger->info('Category webhook processed — sync queued', $context);
    }
}
