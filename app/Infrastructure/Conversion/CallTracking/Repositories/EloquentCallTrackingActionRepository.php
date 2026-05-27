<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Repositories;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\Conversion\CallTracking\Enums\CallTrackingActionStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Conversion\CallTracking\Models\CallTrackingActionModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Override;
use RuntimeException;

final readonly class EloquentCallTrackingActionRepository implements CallTrackingActionRepositoryInterface
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
    #[Override]
    public function create(Uuid $visitId, AdPlatform $platform): Uuid
    {
        $id = $this->gateway->insertOne(
            CallTrackingActionModel::class,
            [
                'call_tracking_visit_id' => $visitId->value,
                'ad_platform' => $platform,
                'status' => CallTrackingActionStatus::Pending,
                'attempts' => 0,
            ],
        );

        return Uuid::fromTrusted($id);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markProcessing(string $actionId): void
    {
        $this->gateway->updateWhere(
            CallTrackingActionModel::class,
            'id',
            $actionId,
            [
                'status' => CallTrackingActionStatus::Processing,
                'processing_started_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function markCompleted(string $actionId, string $externalId): void
    {
        $this->gateway->updateWhere(
            CallTrackingActionModel::class,
            'id',
            $actionId,
            [
                'status' => CallTrackingActionStatus::Completed,
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
    #[Override]
    public function markFailed(string $actionId, string $errorMessage): void
    {
        $this->gateway->updateWhere(
            CallTrackingActionModel::class,
            'id',
            $actionId,
            [
                'status' => CallTrackingActionStatus::Failed,
                'error_message' => $errorMessage,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function incrementAttempts(string $actionId): void
    {
        $this->gateway->transact(
            static fn(): int => CallTrackingActionModel::query()
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
    #[Override]
    public function findStaleProcessing(DateTimeImmutable $threshold): array
    {
        return $this->gateway->query(
            static fn(): array => CallTrackingActionModel::query()
                ->where('status', CallTrackingActionStatus::Processing)
                ->where('processing_started_at', '<', $threshold)
                ->get(['id', 'call_tracking_visit_id'])
                ->map(static fn(CallTrackingActionModel $model): array => [
                    'action_id' => $model->id,
                    'parent_id' => $model->call_tracking_visit_id,
                ])
                ->all(),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function resetToPending(string $actionId): void
    {
        $this->gateway->updateWhere(
            CallTrackingActionModel::class,
            'id',
            $actionId,
            [
                'status' => CallTrackingActionStatus::Pending,
                'processing_started_at' => null,
            ],
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function getStatus(string $actionId): ?CallTrackingActionStatus
    {
        return $this->gateway->query(
            static function () use ($actionId): ?CallTrackingActionStatus {
                /** @var CallTrackingActionModel|null $model */
                $model = CallTrackingActionModel::query()
                    ->where('id', $actionId)
                    ->first(['status']);

                return $model?->status;
            },
        );
    }
}
