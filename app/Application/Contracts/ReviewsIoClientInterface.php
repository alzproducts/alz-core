<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Product\ValueObjects\ProductRating;
use InvalidArgumentException;

/**
 * Contract for Reviews.io API client.
 *
 * This interface defines the boundary between Application and Infrastructure
 * for Reviews.io product rating operations. Implementation handles HTTP
 * communication, authentication, and response parsing.
 *
 * @template-pattern API Client Interface
 */
interface ReviewsIoClientInterface
{
    /**
     * Get product ratings by SKU in batch.
     *
     * Retrieves average ratings and review counts for the provided SKUs.
     * Returns an array indexed by integer keys (not SKU keys).
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
     *
     * @return list<ProductRating> Array of rating data
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidApiResponseException When response structure is invalid
     * @throws InvalidArgumentException When provided SKUs are invalid
     */
    public function getProductRatingBatch(array|string $skus): array;

    /**
     * Verify API connectivity and authentication.
     *
     * Performs a lightweight request to validate credentials without
     * business logic side effects. Used for health checks and diagnostics.
     *
     * @throws ExternalServiceUnavailableException When API unavailable or auth fails
     */
    public function verifyConnectivity(): void;
}
