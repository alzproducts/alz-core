<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\ShippingOptionsFilterValue;
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
 * Queries the catalog.products_with_changed_shipping_options_filters Postgres view.
 *
 * All filtering logic lives in the SQL view — this repository is a trivial
 * SELECT that maps rows to DTOs. The `desired_filter_values` column is jsonb
 * (not text[]) because Shipping Options filter values contain whitespace.
 */
final class ShippingOptionsFilterQueryRepository implements ShippingOptionsFilterQueryRepositoryInterface
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
    public function getProductsWithChangedShippingOptionsFilters(): array
    {
        /** @var list<object{product_id: int, desired_filter_values: string}> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
            ->getConnection()
            ->select('SELECT product_id, desired_filter_values FROM catalog.products_with_changed_shipping_options_filters'));

        return self::mapRowsToDtos($rows, FilterGroupOptionNo::ShippingOptions->value);
    }

    /**
     * @param  list<object{product_id: int, desired_filter_values: string}>  $rows
     * @return list<ProductFilterChangeDTO>
     *
     * @throws InvalidEnumValueException
     */
    private static function mapRowsToDtos(array $rows, int $optionNo): array
    {
        return \array_map(
            static fn(object $row): ProductFilterChangeDTO => new ProductFilterChangeDTO(
                productId: IntId::from($row->product_id),
                optionNo: $optionNo,
                desiredFilterValues: ShippingOptionsFilterValue::fromJsonArray($row->desired_filter_values),
            ),
            $rows,
        );
    }
}
