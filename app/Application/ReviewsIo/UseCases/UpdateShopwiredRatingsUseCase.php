<?php

declare(strict_types=1);

namespace App\Application\ReviewsIo\UseCases;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\ReviewsIo\Results\RatingsUpdateResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Push aggregated product ratings to ShopWired custom fields.
 *
 * Uses a single SQL query to find products where ratings have changed,
 * then updates only those products via the ShopWired API.
 */
final readonly class UpdateShopwiredRatingsUseCase
{
    private const string FIELD_AVERAGE_RATING = 'average_rating';
    private const string FIELD_NUM_RATINGS = 'num_ratings';

    public function __construct(
        private ProductRatingRepositoryInterface $ratingRepository,
        private ProductUpdateClientInterface $updateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Update ShopWired custom fields for products with changed ratings.
     *
     * @throws DatabaseOperationFailedException When database query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws InvalidApiRequestException When request invalid
     * @throws InvalidApiResponseException When response parsing fails
     * @throws ResourceNotAvailableException When ShopWired resource not available
     */
    public function execute(): RatingsUpdateResult
    {
        $this->logger->info('Starting ShopWired ratings update');

        // Single SQL query finds products with changed ratings
        $changedProducts = $this->ratingRepository->getProductsWithChangedRatings();

        if ($changedProducts === []) {
            $this->logger->info('No products with changed ratings');

            return new RatingsUpdateResult(0, 0, 0, 0);
        }

        $this->logger->info('Found products with changed ratings', [
            'count' => \count($changedProducts),
        ]);

        $updated = 0;
        $failed = 0;
        /** @var list<int> $failedProductIds */
        $failedProductIds = [];

        foreach ($changedProducts as $change) {
            try {
                $this->updateClient->updateCustomFields($change->productId->value, [
                    self::FIELD_AVERAGE_RATING => $change->newAverageRating,
                    self::FIELD_NUM_RATINGS => (string) $change->newNumRatings,
                ]);
                $updated++;
            } catch (ResourceNotAvailableException) {
                $this->logger->warning('Product not available in ShopWired', [
                    'product_id' => $change->productId->value,
                ]);
                $failed++;
                $failedProductIds[] = $change->productId->value;
            }
        }

        $this->logger->info('ShopWired ratings update completed', [
            'processed' => \count($changedProducts),
            'updated' => $updated,
            'skipped' => 0,
            'failed' => $failed,
        ]);

        return new RatingsUpdateResult(
            processed: \count($changedProducts),
            updated: $updated,
            skipped: 0,
            failed: $failed,
            failedProductIds: $failedProductIds,
        );
    }
}
