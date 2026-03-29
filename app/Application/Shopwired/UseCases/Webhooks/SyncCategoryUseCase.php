<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
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
        private ShopwiredSyncDispatcherInterface $dispatcher,
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
    public function execute(WebhookContextDTO $context, Category $category, array $presentEmbeds = []): void
    {
        $this->process($context, $category->id, $category, $presentEmbeds);
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
        $this->dispatcher->dispatchCategorySync($entityId);
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Category';
    }
}
