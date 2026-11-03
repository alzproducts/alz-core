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

    private const int LOGGED_SKIPPED_SKU_LIMIT = 50;

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

        $this->logger->info('Starting Reviews.io ratings sync', ['total_skus' => \count($allSkus)]);
        $result = $this->processRatingBatches($this->filterAcceptedSkus($allSkus));

        $this->logger->info('Ratings sync completed', [
            'skus_queried' => $result->fetched,
            'skus_skipped' => \count($allSkus) - $result->fetched,
            'ratings_saved' => $result->saved,
            'failed' => $result->failed,
        ]);

        return $result;
    }

    /**
     * Drop SKUs the client would reject, so one bad SKU cannot fail a whole batch.
     *
     * @param list<string> $skus
     *
     * @return list<string>
     *
     * @throws RuntimeException When the SKU validator cannot be resolved
     */
    private function filterAcceptedSkus(array $skus): array
    {
        $accepted = [];
        $skipped = [];

        foreach ($skus as $sku) {
            if ($this->reviewsIoClient->acceptsSku($sku)) {
                $accepted[] = $sku;
            } else {
                $skipped[] = $sku;
            }
        }

        $this->logSkippedSkus($skipped);

        return $accepted;
    }

    /**
     * @param list<string> $skipped
     */
    private function logSkippedSkus(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->logger->warning('Skipping SKUs rejected by Reviews.io client', [
            'skipped_count' => \count($skipped),
            'skipped_skus' => \array_slice($skipped, 0, self::LOGGED_SKIPPED_SKU_LIMIT),
        ]);
    }

    /**
     * Fetch ratings in API-sized chunks, buffer, and flush to DB in larger batches.
     *
     * @param list<string> $allSkus
     *
     * @throws AuthenticationExpiredException When Reviews.io credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    private function processRatingBatches(array $allSkus): SyncResult
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

        return new SyncResult(fetched: \count($allSkus), saved: $totalSaved, failed: $totalFailed);
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
