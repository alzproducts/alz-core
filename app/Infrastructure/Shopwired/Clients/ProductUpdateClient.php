<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;

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
        private ShopwiredHttpTransport $transport,
        private ProductClientInterface $productClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $productId, array $customFields): void
    {
        // Fetch current product to get existing custom fields
        $product = $this->productClient->getProductById($productId);

        // Merge new values with existing (new values overwrite, null removes)
        $mergedFields = $this->mergeCustomFields($product->rawCustomFields, $customFields);

        // PUT the merged custom fields
        $this->transport->put(
            self::ENDPOINT_PRODUCTS . '/' . $productId,
            ['customFields' => $mergedFields],
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
     * @param array<string, string|int|bool|null> $newFields Fields to update
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
}
