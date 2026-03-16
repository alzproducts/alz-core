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
use Override;
use Psr\Log\LoggerInterface;

/**
 * Handle `category.created` / `category.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the category from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncCategoryUseCase extends AbstractSyncEntityWebhookUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        WebhookIdempotencyServiceInterface $idempotency,
        LoggerInterface $logger,
        int $webhookStalenessHours,
    ) {
        parent::__construct($idempotency, $logger, $webhookStalenessHours);
    }

    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Category $category, array $presentEmbeds = []): void
    {
        $this->process($eventTime, $webhookId, $topic, $category->id, $category, $presentEmbeds);
    }

    /**
     * @param list<string> $presentEmbeds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    protected function saveEntity(object $entity, array $presentEmbeds): void
    {
        /** @var Category $entity */
        $this->categoryRepository->saveFromWebhook($entity, $presentEmbeds);
    }

    #[Override]
    protected function dispatchSyncJob(IntId $entityId): void
    {
        SyncShopwiredCategoryJob::dispatch($entityId);
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Category';
    }
}
