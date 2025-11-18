<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\ReviewsIo;

use App\Domain\Review\Rating;
use App\Domain\Review\Validation\ValidSku;
use App\Infrastructure\Exceptions\ReviewsIoApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Throwable;

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
    ) {
        // Validate configuration when client is instantiated
        if ($apiKey === '') {
            throw new RuntimeException('Reviews.io API key cannot be empty');
        }

        if ($storeId === '') {
            throw new RuntimeException('Reviews.io store ID cannot be empty');
        }

        if (($timeout < 1) || ($timeout > 300)) {
            throw new InvalidArgumentException(
                "Timeout must be between 1-300 seconds, got {$timeout}",
            );
        }

        if (($retryTimes < 0) || ($retryTimes > 10)) {
            throw new InvalidArgumentException(
                "Retry times must be between 0-10, got {$retryTimes}",
            );
        }

        if (($retryDelay < 0) || ($retryDelay > 5000)) {
            throw new InvalidArgumentException(
                "Retry delay must be between 0-5000ms, got {$retryDelay}",
            );
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            // Note: Reviews.io API requires credentials via query parameters.
            // Header-based authentication (e.g., Authorization: Bearer) is not supported.
            // See: https://developer.reviews.io/reference/
            ->withQueryParameters([
                'apikey' => $this->apiKey,
                'store' => $this->storeId,
            ])
            ->retry(
                times: $this->retryTimes,
                sleepMilliseconds: $this->retryDelay,
                when: static fn(Throwable $exception) => $exception instanceof ConnectionException,
            )
            ->timeout($this->timeout);
    }

    /**
     * Get product reviews by SKU in batch.
     *
     * Returns a collection of Rating objects indexed by integer keys.
     * Example: [0 => Rating(sku: 'FLP-01', averageRating: 4.5, numRatings: 362)]
     *
     * Note: This method does not cache responses. Implement caching in the
     * Application layer (e.g., CachedRatingService) to avoid unnecessary
     * API calls for frequently accessed product ratings.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
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

        $data = $response->json();

        if (!\is_array($data)) {
            throw ReviewsIoApiException::invalidResponse('Expected array response');
        }

        return Rating::collect($data, DataCollection::class);
    }

}
