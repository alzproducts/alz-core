<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
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
     * @return list<ProductFilterChangeCommand>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    #[Override]
    public function getProductsWithChangedRatingFilters(): array
    {
        /** @var list<object{product_id: int, desired_filter_values: string}> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
            ->getConnection()
            ->select('SELECT product_id, desired_filter_values FROM catalog.products_with_changed_rating_filters'));

        return self::mapRowsToCommands($rows, FilterGroupOptionNo::CustomerRating->value);
    }

    /**
     * @param  list<object{product_id: int, desired_filter_values: string}>  $rows
     * @return list<ProductFilterChangeCommand>
     *
     * @throws InvalidEnumValueException
     */
    private static function mapRowsToCommands(array $rows, int $optionNo): array
    {
        return \array_map(
            static fn(object $row): ProductFilterChangeCommand => new ProductFilterChangeCommand(
                productId: IntId::from($row->product_id),
                optionNo: $optionNo,
                desiredFilterValues: RatingFilterValue::fromPostgresArray($row->desired_filter_values),
            ),
            $rows,
        );
    }
}
