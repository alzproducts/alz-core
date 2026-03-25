<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * ShopWired Product Update Client.
 *
 * Handles product modification operations using fetch-merge-PUT pattern
 * to preserve existing values while updating specific fields.
 */
final readonly class ProductUpdateClient implements ProductUpdateClientInterface
{
    private const string ENDPOINT_PRODUCTS = 'products';

    public function __construct(
        private ShopwiredTransportInterface $transport,
        private ProductClientInterface $productClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $productId, array $customFields): void
    {
        $product = $this->productClient->getProductById($productId);
        $mergedFields = $this->mergeCustomFields($product->rawCustomFields, $customFields);
        $this->updateProductField($productId, 'customFields', $mergedFields);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateFilters(int $productId, array $filters): void
    {
        $product = $this->productClient->getProductById($productId);
        $mergedFilters = $this->mergeFilters($product->rawFilters, $filters);
        $this->updateProductField($productId, 'filters', $mergedFilters);
    }

    /**
     * PUT a single field update to a product.
     *
     * @param array<string|int, mixed> $data Merged field data to send
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    private function updateProductField(int $productId, string $fieldName, array $data): void
    {
        $this->transport->put(
            self::ENDPOINT_PRODUCTS . '/' . $productId,
            [$fieldName => $data],
        );
    }

    /**
     * Merge new custom field values with existing.
     *
     * - Existing fields not in $newFields are preserved
     * - Fields in $newFields overwrite existing
     * - Fields with null value in $newFields are removed
     *
     * @param array<string, mixed> $existing Current custom field values
     * @param array<string, string|int|bool|list<string>|list<int>|null> $newFields Fields to update
     *
     * @return array<string, mixed> Merged custom fields
     */
    private function mergeCustomFields(array $existing, array $newFields): array
    {
        $merged = $existing;

        foreach ($newFields as $name => $value) {
            if ($value === null) {
                unset($merged[$name]);
            } else {
                $merged[$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * Merge new filter values with existing.
     *
     * - Existing filters not in $newFilters are preserved
     * - Filters in $newFilters overwrite existing
     * - Filters with null value in $newFilters are removed
     *
     * @param array<int|string, list<string>> $existing Current filter values
     * @param array<int, list<string>|null> $newFilters Filters to update
     *
     * @return array<int|string, list<string>> Merged filters
     */
    private function mergeFilters(array $existing, array $newFilters): array
    {
        $merged = $existing;

        foreach ($newFilters as $optionNo => $values) {
            if ($values === null) {
                unset($merged[$optionNo]);
            } else {
                $merged[$optionNo] = $values;
            }
        }

        return $merged;
    }
}
