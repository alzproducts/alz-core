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
use Psr\Log\LoggerInterface;

/**
 * Handle `brand.created` / `brand.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the brand from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncBrandUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
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
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Brand $brand, array $presentEmbeds = []): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $brand->id];
        $this->logger->info('Processing brand webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale brand webhook', $context);

            return;
        }

        $brandId = IntId::from($brand->id);

        if ($this->idempotency->isSuperseded($brandId, $topic, $webhookId)) {
            $this->logger->info('Discarding already-processed brand webhook', $context);

            return;
        }

        $this->brandRepository->saveFromWebhook($brand, $presentEmbeds);
        $this->idempotency->record($brandId, $topic, $webhookId, $eventTime);

        SyncShopwiredBrandJob::dispatch($brandId);

        $this->logger->info('Brand webhook processed — sync queued', $context);
    }
}
