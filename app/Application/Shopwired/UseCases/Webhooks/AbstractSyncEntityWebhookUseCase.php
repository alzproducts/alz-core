<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Template method for webhook sync use cases.
 *
 * Centralises the staleness guard, idempotency guard, persist, record, and
 * dispatch algorithm shared by all ShopWired entity webhook handlers.
 *
 * Children keep their strongly-typed `execute()` as the public API and
 * delegate to `process()`.
 */
abstract readonly class AbstractSyncEntityWebhookUseCase
{
    public function __construct(
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * Template method: staleness → idempotency → save → record → dispatch.
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    protected function process(
        WebhookContextDTO $context,
        int $entityExternalId,
        object $entity,
        array $presentEmbeds = [],
    ): void {
        $label = $this->entityLabel();
        $labelLower = \mb_strtolower($label);
        $logContext = ['webhook_id' => $context->webhookId, 'subject_id' => $entityExternalId];

        $this->logger->info("Processing {$labelLower} webhook", $logContext);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($context->eventTime < $cutoff) {
            $this->logger->info("Discarding stale {$labelLower} webhook", $logContext);

            return;
        }

        $entityId = IntId::from($entityExternalId);

        if ($this->idempotency->isSuperseded($entityId, $context->topic, $context->webhookId)) {
            $this->logger->info("Discarding already-processed {$labelLower} webhook", $logContext);

            return;
        }

        $this->saveEntity($entity, $presentEmbeds);
        $this->idempotency->record($entityId, $context->topic, $context->webhookId, $context->eventTime);

        $this->dispatchSyncJob($entityId);

        $this->logger->info("{$label} webhook processed — sync queued", $logContext);
    }

    /** @param list<string> $presentEmbeds */
    abstract protected function saveEntity(object $entity, array $presentEmbeds): void;

    abstract protected function dispatchSyncJob(IntId $entityId): void;

    abstract protected function entityLabel(): string;
}
