<?php

declare(strict_types=1);

namespace App\Infrastructure\Api;

use App\Domain\Review\Rating;
use App\Domain\Review\Validation\ValidSku;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\DataCollection;

/**
 * Reviews.io API Client (Synchronous HTTP)
 *
 * Makes blocking HTTP calls to Reviews.io API. Use within Laravel
 * Jobs/Queues for async execution in production.
 *
 * Design Philosophy: "Thin SDK"
 * - No caching (implement in Application layer via CachedRatingService)
 * - No business logic (pure HTTP + validation)
 * - Simple error handling (throw on failures)
 *
 * Authentication: Reviews.io API requires credentials via query parameters
 * (header-based auth is not supported per official API documentation).
 *
 * @see https://developer.reviews.io Official API documentation
 */
final readonly class ReviewsIoClient
{
    private const string BASE_URL = 'https://api.reviews.co.uk/';
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private string $apiKey,
        private string $storeId,
        private int $timeout = 30,
        private int $retryTimes = 3,
        private int $retryDelay = 100,
    ) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->retry($this->retryTimes, $this->retryDelay, throw: false)
            // Note: Reviews.io API requires credentials via query parameters.
            // Header-based authentication (e.g., Authorization: Bearer) is not supported.
            // See: https://developer.reviews.io/reference/
            ->withQueryParameters([
                'apikey' => $this->apiKey,
                'store' => $this->storeId,
            ])
            ->timeout($this->timeout);
    }

    /**
     * Get product reviews by SKU in batch.
     * $this->assertInstanceOf(DataCollection::class, $result);
     * Returns a collection of Rating objects indexed by integer keys.
     * Example: [0 => Rating(sku: 'FLP-01', averageRating: 4.5, numRatings: 362)]
     * Note: This method does not cache responses. Implement caching in the
     * Application layer (e.g., CachedRatingService) to avoid unnecessary
     * API calls for frequently accessed product ratings.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
     *
     * @return DataCollection<int, Rating> Collection of rating data
     * @throws ValidationException If SKU parameter is invalid
     * @throws RequestException|ConnectionException If API request fails
     */
    public function getProductRatingBatch(array|string $skus): DataCollection
    {
        $skuArray = \is_array($skus) ? $skus : [$skus];

        $validated = Validator::make(
            ['skus' => $skuArray],
            [
                'skus' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH_SIZE], // Batch limit
                'skus.*' => ['required', 'string', 'min:1', 'max:50', new ValidSku()],
            ],
        )->validate();

        /** @var array<string> $validatedSkus */
        $validatedSkus = $validated['skus'];

        $response = $this->http()
            ->get('product/rating-batch', [
                'sku' => \implode(';', $validatedSkus),
            ])
            ->throw();

        /** @var array<mixed> $data */
        $data = $response->json() ?? [];

        return Rating::collect($data, DataCollection::class);
    }

}
