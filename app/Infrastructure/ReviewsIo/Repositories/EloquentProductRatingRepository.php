<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Repositories;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Application\ReviewsIo\DTOs\ProductRatingChangeDTO;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\ReviewsIo\Models\ProductRatingModel;

/**
 * Eloquent implementation of Reviews.io product ratings repository.
 *
 * Persists Domain ProductRating entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on SKU for idempotent sync.
 *
 * @extends AbstractEloquentRepository<ProductRating>
 */
final class EloquentProductRatingRepository extends AbstractEloquentRepository implements ProductRatingRepositoryInterface
{
    /** @var class-string<ProductRatingModel> */
    private const string MODEL_CLASS = ProductRatingModel::class;

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @return list<ProductRating>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getBySkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return $this->eloquentGateway->query(static fn(): array => \array_values(
            self::MODEL_CLASS::query()
                ->whereIn('sku', $skus)
                ->get()
                ->map(static fn(ProductRatingModel $model): ProductRating => new ProductRating(
                    sku: $model->sku,
                    averageRating: $model->average_rating,
                    numRatings: $model->num_ratings,
                ))
                ->all(),
        ));
    }

    /**
     * {@inheritDoc}
     *
     * @return list<ProductRatingChangeDTO>
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
                'SELECT product_id, new_average, new_count FROM reviews_io.products_with_changed_ratings',
            );

            return \array_map(
                static fn(object $row): ProductRatingChangeDTO => new ProductRatingChangeDTO(
                    productId: IntId::from($row->product_id),
                    newAverageRating: $row->new_average,
                    newNumRatings: $row->new_count,
                ),
                $rows,
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var ProductRating $entity */
        return $entity->sku;
    }

    /**
     * {@inheritDoc}
     *
     * @param ProductRating $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'sku' => $entity->sku,
            'average_rating' => $entity->averageRating,
            'num_ratings' => $entity->numRatings,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['sku'];
    }
}
