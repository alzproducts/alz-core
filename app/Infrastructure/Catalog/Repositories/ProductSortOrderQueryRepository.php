<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\DTOs\ProductSortOrderChangeDTO;
use App\Application\Contracts\Catalog\ProductSortOrderQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Queries the catalog.product_popularity_ranking_latest view joined against
 * live shopwired.products to find active products whose sort order needs correcting.
 *
 * Comparing against the live sort_order (not the snapshot's current_sort_order)
 * ensures idempotency: re-running after a successful bulk dispatch returns zero rows.
 */
final class ProductSortOrderQueryRepository implements ProductSortOrderQueryRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<ProductSortOrderChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function getProductsWithSortOrderDifferences(): array
    {
        /** @var list<object{product_id: int, calculated_sort_order: int}> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
            ->getConnection()
            ->select(
                <<<'SQL'
                SELECT
                    l.parent_external_id     AS product_id,
                    l.calculated_sort_order
                FROM catalog.product_popularity_ranking_latest l
                INNER JOIN shopwired.products p
                    ON p.external_id = l.parent_external_id
                WHERE p.is_active = true
                  AND l.calculated_sort_order IS DISTINCT FROM p.sort_order
                SQL,
            ));

        return self::mapRowsToDtos($rows);
    }

    /**
     * @param  list<object{product_id: int, calculated_sort_order: int}>  $rows
     * @return list<ProductSortOrderChangeDTO>
     */
    private static function mapRowsToDtos(array $rows): array
    {
        return \array_map(
            static fn(object $row): ProductSortOrderChangeDTO => new ProductSortOrderChangeDTO(
                productId: IntId::from($row->product_id),
                sortOrder: $row->calculated_sort_order,
            ),
            $rows,
        );
    }
}
