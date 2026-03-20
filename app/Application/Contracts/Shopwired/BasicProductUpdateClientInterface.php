<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

/**
 * Update non-price attributes on ShopWired products and variations via PUT.
 *
 * Supports: SKU, costPrice, weight, GTIN.
 * Price/salePrice use the dedicated POST products/prices endpoint.
 *
 * Handles product vs variation endpoint routing internally based on SKU lookup.
 */
interface BasicProductUpdateClientInterface
{
    /**
     * @throws ResourceNotFoundException When SKU not found locally
     * @throws ResourceNotAvailableException When product/variation not found in ShopWired
     * @throws InvalidApiRequestException When update parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(UpdateBasicProductCommand $command): void;
}
