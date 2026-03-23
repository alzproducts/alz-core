<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\ProductSaleSettingsModel;
use Override;

/**
 * Eloquent implementation of SaleSettingsRepositoryInterface.
 *
 * Maps SaleSettings VO ↔ ProductSaleSettingsModel columns.
 * Uses upsert on product_external_id for idempotent writes.
 */
final readonly class EloquentSaleSettingsRepository implements SaleSettingsRepositoryInterface
{
    /** @var class-string<ProductSaleSettingsModel> */
    private const string MODEL_CLASS = ProductSaleSettingsModel::class;

    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function save(IntId $productId, SaleSettings $settings): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: self::MODEL_CLASS,
            attributes: [
                'product_external_id' => $productId->value,
                'sale_reason' => $settings->saleReason,
                'sale_comments' => $settings->saleComments,
                'sale_start_date' => $settings->saleStartDate?->format('Y-m-d'),
                'sale_end_date' => $settings->saleEndDate?->format('Y-m-d'),
                'sale_ends_stock' => $settings->saleEndsStock,
            ],
            uniqueBy: ['product_external_id'],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findByProduct(IntId $productId): ?SaleSettings
    {
        return $this->eloquentGateway->query(static function () use ($productId): ?SaleSettings {
            $model = ProductSaleSettingsModel::query()
                ->where('product_external_id', $productId->value)
                ->first();

            return $model?->toDomain();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function delete(IntId $productId): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: self::MODEL_CLASS,
            column: 'product_external_id',
            value: $productId->value,
        );
    }
}
