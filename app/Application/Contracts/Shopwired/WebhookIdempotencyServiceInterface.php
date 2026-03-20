<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Centralised webhook idempotency service.
 *
 * Tracks processed webhook events by (subject_id, topic, webhook_id) to prevent
 * duplicate or out-of-order processing. Uses ShopWired's monotonically increasing
 * webhook_id for ordering instead of timestamps.
 */
interface WebhookIdempotencyServiceInterface
{
    /**
     * Returns true if a webhook with same or higher ID was already processed
     * for this (subject_id, topic) pair.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function isSuperseded(IntId $subjectId, WebhookTopic $topic, int $webhookId): bool;

    /**
     * Record a successfully processed webhook event.
     *
     * Call this AFTER successful processing so that retries on failure
     * are not incorrectly rejected as duplicates.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function record(IntId $subjectId, WebhookTopic $topic, int $webhookId, DateTimeImmutable $eventTime): void;

    /**
     * Delete webhook events older than the given cutoff date.
     *
     * @return int Number of deleted rows
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function cleanup(DateTimeImmutable $before): int;
}
