<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredBrandJob;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Catalog\ValueObjects\Brand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Handle `brand.created` / `brand.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the brand from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncBrandUseCase extends AbstractSyncEntityWebhookUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
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
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Brand $brand, array $presentEmbeds = []): void
    {
        $this->process($eventTime, $webhookId, $topic, $brand->id, $brand, $presentEmbeds);
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
        /** @var Brand $entity */
        $this->brandRepository->saveFromWebhook($entity, $presentEmbeds);
    }

    #[Override]
    protected function dispatchSyncJob(IntId $entityId): void
    {
        SyncShopwiredBrandJob::dispatch($entityId);
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Brand';
    }
}
