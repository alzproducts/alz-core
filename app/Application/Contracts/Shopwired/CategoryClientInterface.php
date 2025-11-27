<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * ShopWired Categories API client.
 *
 * Handles category retrieval operations from ShopWired API.
 * Implementation handles HTTP communication, authentication, and response parsing.
 */
interface CategoryClientInterface
{
    /**
     * List ALL categories with embedded parents (paginated fetch).
     *
     * Fetches all pages automatically. Use for complete category tree building/caching.
     *
     * @return list<Category>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listAllCategories(): array;

    /**
     * List categories (single page).
     *
     * @return list<Category>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function listCategories(): array;

    /**
     * Get a single category by ID.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getCategoryById(int $id): Category;

    /**
     * Get the total count of categories.
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getCategoryCount(): int;
}
