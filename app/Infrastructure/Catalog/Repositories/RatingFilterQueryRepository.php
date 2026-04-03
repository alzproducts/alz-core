<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Enums\FilterGroupOptionNo;
use Override;

/**
 * Queries the catalog.products_with_changed_rating_filters Postgres view.
 *
 * All filtering and diff logic lives in the SQL view — this repository
 * is a trivial SELECT that maps rows to DTOs.
 */
final class RatingFilterQueryRepository implements RatingFilterQueryRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<ProductFilterChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    #[Override]
    public function getProductsWithChangedRatingFilters(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            /** @var list<object{product_id: int, desired_filter_values: string}> $rows */
            $rows = self::MODEL_CLASS::query()->getConnection()->select(
                'SELECT product_id, desired_filter_values FROM catalog.products_with_changed_rating_filters',
            );

            $optionNo = FilterGroupOptionNo::CustomerRating->value;

            return \array_map(
                static fn(object $row): ProductFilterChangeDTO => ProductFilterChangeDTO::fromViewRow(
                    $row->product_id,
                    $row->desired_filter_values,
                    $optionNo,
                ),
                $rows,
            );
        });
    }
}
