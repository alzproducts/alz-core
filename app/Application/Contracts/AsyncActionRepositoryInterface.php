<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use BackedEnum;
use DateTimeImmutable;

/**
 * Generic interface for async action tracking repositories.
 *
 * Provides status management for queue-based processing workflows.
 * Implementations track action status, attempts, and stale detection
 * for reliable async processing with retry capabilities.
 *
 * Status lifecycle: pending → processing → completed|failed
 *
 * @template TStatus of \BackedEnum The status enum type for this action
 */
interface AsyncActionRepositoryInterface
{
    /**
     * Mark an action as started processing.
     *
     * Sets status to 'processing' and records processing_started_at for stale detection.
     *
     * @throws DatabaseOperationFailedException On update failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function markProcessing(string $actionId): void;

    /**
     * Mark an action as successfully completed.
     *
     * @param string $externalId External system reference (e.g., HelpScout conversation ID)
     *
     * @throws DatabaseOperationFailedException On update failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function markCompleted(string $actionId, string $externalId): void;

    /**
     * Mark an action as permanently failed.
     *
     * @throws DatabaseOperationFailedException On update failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function markFailed(string $actionId, string $errorMessage): void;

    /**
     * Increment the attempt counter.
     *
     * @throws DatabaseOperationFailedException On update failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function incrementAttempts(string $actionId): void;

    /**
     * Find actions stuck in 'processing' status for longer than threshold.
     *
     * Used by cleanup jobs to detect and reset stale actions.
     *
     * @return array<int, array{action_id: string, parent_id: string}> Stale action records
     *
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function findStaleProcessing(DateTimeImmutable $threshold): array;

    /**
     * Reset a stale action back to pending status.
     *
     * @throws DatabaseOperationFailedException On update failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function resetToPending(string $actionId): void;

    /**
     * Get the current status of an action.
     *
     * @return TStatus|null The current status, or null if action not found
     *
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function getStatus(string $actionId): ?BackedEnum;
}
