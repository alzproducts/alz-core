<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Services;

use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\WebhookEventModel;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Centralised webhook idempotency using the shopwired.webhook_events table.
 *
 * Uses ShopWired's monotonically increasing webhook_id for ordering
 * instead of timestamps, preventing cross-topic event loss.
 */
final readonly class EloquentWebhookIdempotencyService implements WebhookIdempotencyServiceInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
        private LoggerInterface $logger,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function isSuperseded(IntId $subjectId, WebhookTopic $topic, int $webhookId): bool
    {
        return $this->eloquentGateway->query(static fn(): bool => WebhookEventModel::query()
            ->where('subject_id', $subjectId->value)
            ->where('topic', $topic->value)
            ->where('webhook_id', '>=', $webhookId)
            ->exists());
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function record(IntId $subjectId, WebhookTopic $topic, int $webhookId, DateTimeImmutable $eventTime): void
    {
        try {
            $this->eloquentGateway->upsertOne(
                modelClass: WebhookEventModel::class,
                attributes: [
                    'subject_id' => $subjectId->value,
                    'topic' => $topic->value,
                    'webhook_id' => $webhookId,
                    'event_time' => $eventTime,
                ],
                uniqueBy: ['webhook_id'],
            );
        } catch (DuplicateRecordException) {
            $this->logger->info('Duplicate webhook event already recorded', [
                'webhook_id' => $webhookId,
                'subject_id' => $subjectId->value,
                'topic' => $topic->value,
            ]);
        }
    }
}
