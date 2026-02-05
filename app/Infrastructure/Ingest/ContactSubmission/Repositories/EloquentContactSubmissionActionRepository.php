<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Repositories;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionActionModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use RuntimeException;

/**
 * Eloquent implementation of ContactSubmissionActionRepository.
 *
 * Manages mutable processing state in customer_service schema.
 * Uses EloquentGateway for model-agnostic operations and exception translation.
 */
final readonly class EloquentContactSubmissionActionRepository implements ContactSubmissionActionRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException
     */
    public function create(string $submissionId, ActionType $actionType): string
    {
        return $this->gateway->insertOne(
            ContactSubmissionActionModel::class,
            [
                'contact_submission_id' => $submissionId,
                'action_type' => $actionType,
                'status' => ActionStatus::Pending,
                'attempts' => 0,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markProcessing(string $actionId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Processing,
                'processing_started_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markCompleted(string $actionId, string $externalId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Completed,
                'external_id' => $externalId,
                'completed_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markFailed(string $actionId, string $errorMessage): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Failed,
                'error_message' => $errorMessage,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function incrementAttempts(string $actionId): void
    {
        $this->gateway->transact(
            static fn(): int => ContactSubmissionActionModel::query()
                ->where('id', $actionId)
                ->increment('attempts'),
        );
    }

    /**
     * @return array<int, array{action_id: string, parent_id: string}>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findStaleProcessing(DateTimeImmutable $threshold): array
    {
        return $this->gateway->query(
            static fn(): array => ContactSubmissionActionModel::query()
                    ->where('status', ActionStatus::Processing)
                    ->where('processing_started_at', '<', $threshold)
                    ->get(['id', 'contact_submission_id'])
                    ->map(static fn(ContactSubmissionActionModel $model): array => [
                        'action_id' => $model->id,
                        'parent_id' => $model->contact_submission_id,
                    ])
                    ->all(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function resetToPending(string $actionId): void
    {
        $this->gateway->updateWhere(
            ContactSubmissionActionModel::class,
            'id',
            $actionId,
            [
                'status' => ActionStatus::Pending,
                'processing_started_at' => null,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getStatus(string $actionId): ?ActionStatus
    {
        return $this->gateway->query(
            static function () use ($actionId): ?ActionStatus {
                /** @var ContactSubmissionActionModel|null $model */
                $model = ContactSubmissionActionModel::query()
                    ->where('id', $actionId)
                    ->first(['status']);

                return $model?->status;
            },
        );
    }
}
