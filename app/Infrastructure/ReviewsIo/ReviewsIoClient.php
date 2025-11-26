<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Application\Contracts\ReviewsIoClientInterface;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Product\ValueObjects\ProductRating;
use App\Infrastructure\ReviewsIo\Responses\Rating;
use App\Infrastructure\ReviewsIo\Validation\ValidSku;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

    private const string HEALTH_CHECK_SKU = 'VERIFY-CONNECTIVITY-HEALTH-CHECK';

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
            'sku' => self::HEALTH_CHECK_SKU,
        ]);
    }

    /**
     * Get product ratings by SKU in batch.
     *
     * Returns an array of ProductRating value objects indexed by integer keys.
     * Example: [0 => ProductRating(sku: 'FLP-01', averageRating: 4.5, numRatings: 362)]
     *
     * Note: This method does not cache responses. Implement caching in the
     * Application layer (e.g., CachedRatingService) to avoid unnecessary
     * API calls for frequently accessed product ratings.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
     *
     * @return list<ProductRating> Array of rating data
     */
    public function getProductRatingBatch(array|string $skus): array
    {
        $validatedSkus = $this->validateSkus($skus);

        $response = $this->transport->get(self::ENDPOINT_RATING_BATCH, [
            'sku' => \implode(ReviewsIoConfig::SKU_DELIMITER, $validatedSkus),
        ]);

        $infraRatings = $this->parseArrayResponse($response->json(), Rating::class);

        /** @var array<int, Rating> $ratingsArray */
        $ratingsArray = $infraRatings->all();

        return self::mapToProductRatings($ratingsArray);
    }

    /**
     * Validate input data and wrap validation failures.
     * Executes Laravel validation and translates ValidationException
     * to InvalidArgumentException with consistent error formatting.
     *
     * @param array<string, mixed> $data Data to validate
     * @param array<string, array<mixed>> $rules Laravel validation rules
     * @param string $inputDescription Human-readable description for error messages
     *
     * @return array<string, mixed> Validated data
     * @throws InvalidArgumentException When validation fails
     * @noinspection PhpSameParameterValueInspection*/
    private function validateInput(array $data, array $rules, string $inputDescription): array
    {
        try {
            /** @var array<string, mixed> */
            return Validator::make($data, $rules)->validate();
        } catch (ValidationException $e) {
            throw new InvalidArgumentException(
                "Invalid {$inputDescription} provided: " . \implode(', ', \array_keys($e->errors())),
                previous: $e,
            );
        }
    }

    /**
     * Validate and normalize SKU input.
     *
     * Accepts single SKU string or array of SKUs. Validates against
     * batch size limits, string requirements, and SKU format rules.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs
     *
     * @return array<string> Validated SKU array
     *
     * @throws InvalidArgumentException When SKUs are invalid
     */
    private function validateSkus(array|string $skus): array
    {
        $skuArray = \is_array($skus) ? $skus : [$skus];

        $validated = $this->validateInput(
            ['skus' => $skuArray],
            [
                'skus' => ['required', 'array', 'min:1', 'max:' . ReviewsIoConfig::MAX_BATCH_SIZE],
                'skus.*' => ['required', 'string', 'min:1', 'max:' . ReviewsIoConfig::MAX_SKU_LENGTH, new ValidSku()],
            ],
            'SKU(s)',
        );

        /** @var array<string> */
        return $validated['skus'];
    }

    /**
     * Parse API response expecting an array of DTOs.
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return DataCollection<int, T>
     * @throws InvalidApiResponseException When response structure is invalid
     * @noinspection PhpSameParameterValueInspection*/
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
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CannotCreateData $e) {
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

    /**
     * Map infrastructure ratings to domain value objects, skipping invalid entries.
     *
     * Invalid ratings (those violating domain business rules) are logged and skipped
     * rather than failing the entire batch. This provides resilience against
     * unexpected API data while maintaining visibility into data quality issues.
     *
     * @param array<int, Rating> $ratings Infrastructure DTOs from API response
     *
     * @return list<ProductRating> Valid domain value objects
     */
    private static function mapToProductRatings(array $ratings): array
    {
        return \array_values(\array_filter(
            \array_map(
                static function (Rating $rating): ?ProductRating {
                    try {
                        return $rating->toProductRating();
                    } catch (InvalidArgumentException) {
                        Log::warning(self::SERVICE_NAME . ' API returned invalid rating, skipping', [
                            'sku' => $rating->sku,
                            'average_rating' => $rating->averageRating,
                            'num_ratings' => $rating->numRatings,
                        ]);

                        return null;
                    }
                },
                $ratings,
            ),
        ));
    }
}
