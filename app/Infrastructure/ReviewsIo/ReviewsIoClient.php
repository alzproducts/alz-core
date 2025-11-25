<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\DTOs\ProductRatingDTO;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\ReviewsIo\Responses\Rating;
use App\Infrastructure\ReviewsIo\Validation\ValidSku;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Reviews.io API Client.
 *
 * Handles business logic for Reviews.io API interactions:
 * - Input validation (SKUs)
 * - Response parsing and DTO creation
 * - Domain exception wrapping for parse failures
 *
 * HTTP concerns (auth, retry, timeout, exception translation) are delegated
 * to ReviewsIoHttpTransport, following the separation of concerns principle.
 *
 * Design Philosophy: "Thin SDK"
 * - No caching (implement in Application layer via CachedRatingService)
 * - No business logic beyond validation and parsing
 * - Simple error handling (throw on failures)
 *
 * @template-pattern API Client (Template Pattern)
 * @see https://developer.reviews.io Official API documentation
 */
final readonly class ReviewsIoClient implements ReviewsIoClientInterface
{
    private const string SERVICE_NAME = 'Reviews.io';

    private const string ENDPOINT_RATING_BATCH = 'product/rating-batch';

    public function __construct(
        private ReviewsIoHttpTransport $transport,
    ) {}

    /**
     * Verify API connectivity and authentication.
     *
     * Makes a minimal batch request with a placeholder SKU to verify
     * credentials work. The API will return an empty array for unknown SKUs,
     * which is fine - we only care that auth succeeds.
     */
    public function verifyConnectivity(): void
    {
        // Use batch endpoint with a dummy SKU - returns empty array but validates auth
        $this->transport->get(self::ENDPOINT_RATING_BATCH, [
            'sku' => 'VERIFY-CONNECTIVITY-HEALTH-CHECK',
        ]);
    }

    /**
     * Get product ratings by SKU in batch.
     *
     * Returns a collection of ProductRatingDTO objects indexed by integer keys.
     * Example: [0 => ProductRatingDTO(sku: 'FLP-01', averageRating: 4.5, numRatings: 362)]
     *
     * Note: This method does not cache responses. Implement caching in the
     * Application layer (e.g., CachedRatingService) to avoid unnecessary
     * API calls for frequently accessed product ratings.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
     *
     * @return DataCollection<int, ProductRatingDTO> Collection of rating data
     */
    public function getProductRatingBatch(array|string $skus): DataCollection
    {
        $skuArray = \is_array($skus) ? $skus : [$skus];

        $validated = Validator::make(
            ['skus' => $skuArray],
            [
                'skus' => ['required', 'array', 'min:1', 'max:' . ReviewsIoConfig::MAX_BATCH_SIZE],
                'skus.*' => ['required', 'string', 'min:1', 'max:' . ReviewsIoConfig::MAX_SKU_LENGTH, new ValidSku()],
            ],
        )->validate();

        /** @var array<string> $validatedSkus */
        $validatedSkus = $validated['skus'];

        $response = $this->transport->get(self::ENDPOINT_RATING_BATCH, [
            'sku' => \implode(ReviewsIoConfig::SKU_DELIMITER, $validatedSkus),
        ]);

        // Parse API response into Infrastructure DTOs, then map to Application DTOs
        $infraRatings = $this->parseArrayResponse($response->json(), Rating::class);

        return ProductRatingDTO::collect(
            $infraRatings->toArray(),
            DataCollection::class,
        );
    }

    /**
     * Parse API response expecting an array of DTOs.
     *
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return DataCollection<int, T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseArrayResponse(mixed $data, string $dtoClass): DataCollection
    {
        if (!\is_array($data)) {
            self::logParsingFailure('Expected array response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected array response',
            );
        }

        try {
            return $dtoClass::collect($data, DataCollection::class);
        } catch (CannotCreateData $e) {
            self::logParsingFailure($e->getMessage(), $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'API returned invalid data structure',
                previous: $e,
            );
        }
    }

    /**
     * Log parsing failure with context for debugging API contract changes.
     */
    private static function logParsingFailure(string $error, mixed $data): void
    {
        Log::critical(self::SERVICE_NAME . ' API response validation failed', [
            'error' => $error,
            'raw_response' => $data,
        ]);
    }
}
