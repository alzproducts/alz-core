<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Repositories;

use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Enums\SyncCursorType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\Models\SyncCursorModel;
use App\Infrastructure\Persistence\EloquentGateway;
use DateTimeImmutable;

/**
 * Eloquent implementation of sync cursor persistence.
 *
 * Stores and retrieves the last-successful-sync timestamp per sync type.
 * Uses upsertOne keyed by sync_type for idempotent upserts.
 */
final readonly class EloquentSyncCursorRepository implements SyncCursorRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function getLastSyncDate(SyncCursorType $syncType): ?DateTimeImmutable
    {
        return $this->gateway->query(static function () use ($syncType): ?DateTimeImmutable {
            /** @var SyncCursorModel|null $model */
            $model = SyncCursorModel::query()
                ->where('sync_type', $syncType->value)
                ->first();

            return $model?->cursor_value->toDateTimeImmutable();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function updateLastSyncDate(SyncCursorType $syncType, DateTimeImmutable $date): void
    {
        $this->gateway->upsertOne(
            modelClass: SyncCursorModel::class,
            attributes: [
                'sync_type' => $syncType->value,
                'cursor_value' => $date,
            ],
            uniqueBy: ['sync_type'],
        );
    }
}
