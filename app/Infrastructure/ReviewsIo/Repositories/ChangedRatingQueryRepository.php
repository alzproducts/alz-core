<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Repositories;

use App\Application\Catalog\Commands\ProductRatingChangeCommand;
use App\Application\Contracts\ReviewsIo\ChangedRatingQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\ReviewsIo\Models\ProductRatingModel;

/**
 * Queries the catalog.products_with_changed_ratings Postgres view.
 *
 * Separated from EloquentProductRatingRepository because this query spans
 * multiple schemas (shopwired + reviews_io) via the catalog view.
 */
final class ChangedRatingQueryRepository implements ChangedRatingQueryRepositoryInterface
{
    /** @var class-string<ProductRatingModel> */
    private const string MODEL_CLASS = ProductRatingModel::class;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return list<ProductRatingChangeCommand>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getProductsWithChangedRatings(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            /** @var list<object{product_id: int, new_average: string|null, new_count: int}> $rows */
            $rows = self::MODEL_CLASS::query()->getConnection()->select(
                'SELECT product_id, new_average, new_count FROM catalog.products_with_changed_ratings',
            );

            return \array_map(
                static fn(object $row): ProductRatingChangeCommand => new ProductRatingChangeCommand(
                    productId: IntId::from($row->product_id),
                    newAverageRating: $row->new_average,
                    newNumRatings: $row->new_count,
                ),
                $rows,
            );
        });
    }
}
