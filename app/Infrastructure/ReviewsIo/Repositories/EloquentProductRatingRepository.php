<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Repositories;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    /**
     * {@inheritDoc}
     */
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
