<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\Checkout\Repositories;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Application\Contracts\Checkout\BasketSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Ingest\Checkout\Mappers\BasketSnapshotMapper;
use App\Infrastructure\Ingest\Checkout\Models\BasketSnapshotModel;
use App\Infrastructure\Persistence\EloquentGateway;
use RuntimeException;

/**
 * Eloquent implementation of {@see BasketSnapshotRepositoryInterface}.
 *
 * Persists immutable basket-snapshot rows via {@see EloquentGateway::insertOne()},
 * returning the generated UUID.
 */
final readonly class EloquentBasketSnapshotRepository implements BasketSnapshotRepositoryInterface
{
    public function __construct(
        private EloquentGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException If fillForInsert returns unexpected result (programming error)
     */
    public function save(BasketSnapshotCommand $snapshot): string
    {
        return $this->gateway->insertOne(
            BasketSnapshotModel::class,
            BasketSnapshotMapper::toModelAttributes($snapshot),
        );
    }
}
