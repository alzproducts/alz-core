<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Repositories;

use App\Application\Contracts\Operations\SkuChangeRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Operations\Models\SkuChangeModel;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of SKU change audit repository.
 *
 * Manages audit records for cross-platform SKU updates. Each record tracks:
 * - The old and new SKU values
 * - Business reason for the change
 * - Success (completed_at set) or failure (error_message set)
 *
 * Uses query builder via DatabaseGateway for optimized operations.
 */
final readonly class EloquentSkuChangeRepository implements SkuChangeRepositoryInterface
{
    public function __construct(
        private DatabaseGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function create(
        string $oldSku,
        Sku $newSku,
        SkuUpdateReason $reason,
    ): string {
        /** @var SkuChangeModel $model */
        $model = $this->gateway->transact(
            static function () use ($oldSku, $newSku, $reason): SkuChangeModel {
                $model = new SkuChangeModel();
                $model->old_sku = $oldSku;
                $model->new_sku = $newSku->value;
                $model->reason = $reason->value;
                $model->save();

                return $model;
            },
        );

        return $model->id;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markComplete(string $id): void
    {
        $this->gateway->transact(
            static fn(): int => SkuChangeModel::query()
                ->where('id', $id)
                ->update(['completed_at' => CarbonImmutable::now()]),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordError(string $id, string $errorMessage): void
    {
        $this->gateway->transact(
            static fn(): int => SkuChangeModel::query()
                ->where('id', $id)
                ->update(['error_message' => $errorMessage]),
        );
    }
}
