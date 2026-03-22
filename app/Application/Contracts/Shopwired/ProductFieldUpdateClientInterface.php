<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * Update individual fields on a ShopWired product via simple PUT.
 *
 * For fetch-merge-PUT fields (customFields, filters), use ProductUpdateClientInterface.
 * For batch endpoints (prices, stock), use PriceUpdateClientInterface / StockClientInterface.
 */
interface ProductFieldUpdateClientInterface
{
    /**
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $productId, ProductFieldUpdate ...$updates): void;
}
