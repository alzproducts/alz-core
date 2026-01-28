<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;

/**
 * ShopWired basic product attribute updates.
 *
 * Handles updates to products and variations by resolving SKU to determine
 * the correct endpoint. Uses partial PUT semantics (missing fields unchanged).
 *
 * Endpoint routing:
 * - Product: PUT products/{id}
 * - Variation: PUT products/{productId}/variations/{variationId}
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class BasicProductUpdateClient implements BasicProductUpdateClientInterface
{
    private const string ENDPOINT_PRODUCTS = 'products';

    public function __construct(
        private ShopwiredHttpTransport $transport,
        private ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When SKU not found locally
     * @throws InvalidApiRequestException When update parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When local lookup fails
     */
    public function update(UpdateBasicProductCommand $command): void
    {
        if (! $command->hasAnyUpdate()) {
            return;
        }

        $entity = $this->productRepository->getBasicProductBySku($command->currentSku);
        $payload = $this->buildPayload($command);

        if ($entity instanceof Product) {
            $this->updateProduct($entity->id, $payload);
        } else {
            $this->updateVariation($entity, $payload);
        }
    }

    /**
     * Build update payload from command.
     *
     * Only includes non-null fields for partial update semantics.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(UpdateBasicProductCommand $command): array
    {
        $payload = [];

        if ($command->newSku !== null) {
            $payload['sku'] = $command->newSku->value;
        }

        // ShopWired expects all prices as gross (tax-inclusive)
        if ($command->price !== null) {
            $payload['price'] = $command->price->toGross();
        }

        if ($command->costPrice !== null) {
            $payload['costPrice'] = $command->costPrice->toGross();
        }

        if ($command->salePrice !== null) {
            $payload['salePrice'] = $command->salePrice->toGross();
        }

        // ShopWired expects weight in kilograms
        if ($command->weight !== null) {
            $payload['weight'] = $command->weight->inKilograms();
        }

        if ($command->gtin !== null) {
            $payload['gtin'] = $command->gtin->value;
        }

        return $payload;
    }

    /**
     * Update a product.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidApiRequestException When update parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When product not found in ShopWired
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function updateProduct(int $productId, array $payload): void
    {
        $this->transport->put(
            self::ENDPOINT_PRODUCTS . '/' . $productId,
            $payload,
        );
    }

    /**
     * Update a variation.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidApiRequestException When update parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When variation not found in ShopWired
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function updateVariation(ProductVariation $variation, array $payload): void
    {
        $endpoint = \sprintf(
            '%s/%d/variations/%d',
            self::ENDPOINT_PRODUCTS,
            $variation->productExternalId,
            $variation->id,
        );

        $this->transport->put($endpoint, $payload);
    }
}
