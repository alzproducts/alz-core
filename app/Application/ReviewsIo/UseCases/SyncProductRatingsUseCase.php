<?php

declare(strict_types=1);

namespace App\Application\ReviewsIo\UseCases;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Application\Results\SyncResult;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sync product ratings from Reviews.io API to local database.
 *
 * Queries all product SKUs against Reviews.io and stores ratings in reviews_io.product_ratings.
 * Only SKUs with reviews are stored; SKUs without reviews are not persisted.
 */
final readonly class SyncProductRatingsUseCase
{
    /**
     * Reviews.io API batch size limit.
     */
    private const int API_BATCH_SIZE = 100;

    /**
     * Database upsert batch size.
     */
    private const int DB_BATCH_SIZE = 1000;

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ReviewsIoClientInterface $reviewsIoClient,
        private ProductRatingRepositoryInterface $ratingRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Fetch ratings from Reviews.io and store locally.
     *
     * @return SyncResult Counts of SKUs fetched, saved, and failed
     *
     * @throws RuntimeException When no SKUs exist (products not synced)
     * @throws AuthenticationExpiredException When Reviews.io credentials invalid
     * @throws ExternalServiceUnavailableException When Reviews.io API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When database operations fail
     */
    public function execute(): SyncResult
    {
        $allSkus = $this->productRepository->getAllSkus();

        if ($allSkus === []) {
            throw new RuntimeException('No SKUs found in product catalog - products must be synced first');
        }

        $totalSkus = \count($allSkus);
        $this->logger->info('Starting Reviews.io ratings sync', ['total_skus' => $totalSkus]);

        [$totalSaved, $totalFailed] = $this->processRatingBatches($allSkus);

        $this->logger->info('Ratings sync completed', [
            'skus_queried' => $totalSkus,
            'ratings_saved' => $totalSaved,
            'failed' => $totalFailed,
        ]);

        return new SyncResult(fetched: $totalSkus, saved: $totalSaved, failed: $totalFailed);
    }

    /**
     * Fetch ratings in API-sized chunks, buffer, and flush to DB in larger batches.
     *
     * @param list<string> $allSkus
     *
     * @return array{int<0, max>, int<0, max>} [totalSaved, totalFailed]
     *
     * @throws AuthenticationExpiredException When Reviews.io credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    private function processRatingBatches(array $allSkus): array
    {
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<ProductRating> $buffer */
        $buffer = [];

        foreach (\array_chunk($allSkus, self::API_BATCH_SIZE) as $skuBatch) {
            $ratings = $this->reviewsIoClient->getProductRatingBatch($skuBatch);
            $buffer = [...$buffer, ...$ratings];

            if (\count($buffer) >= self::DB_BATCH_SIZE) {
                $result = $this->flushBuffer($buffer);
                $totalSaved += $result->succeeded;
                $totalFailed += $result->failed;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer);
            $totalSaved += $result->succeeded;
            $totalFailed += $result->failed;
        }

        return [$totalSaved, $totalFailed];
    }

    /**
     * Flush buffered ratings to database.
     *
     * @param list<ProductRating> $ratings
     *
     * @throws ExternalServiceUnavailableException
     */
    private function flushBuffer(array $ratings): SaveManyResult
    {
        $this->logger->debug('Flushing ratings to database', ['count' => \count($ratings)]);

        return $this->ratingRepository->saveMany($ratings);
    }
}
