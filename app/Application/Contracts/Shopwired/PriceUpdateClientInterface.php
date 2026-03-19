<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateClientResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Client for batch price updates via POST products/prices.
 *
 * Accepts any number of commands — the client handles chunking into
 * batches of 15 (ShopWired's limit) internally.
 *
 * Returns partial results on batch transport failures (caller must
 * check $transportFailures). Per-item failures (updated: false) are
 * included in the results list for the caller to classify.
 *
 * Follows the StockClientInterface pattern: return all valid information
 * and let the caller decide how to handle failures.
 */
interface PriceUpdateClientInterface
{
    /**
     * Update prices for the given commands via batch API.
     *
     * Sends items in concurrent batches of 15. On partial batch transport
     * failures, successful batches are still included in the result. The
     * caller must check $transportFailures and handle accordingly.
     *
     * @param list<UpdatePriceCommand> $commands Any size — client handles chunking
     *
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails
     */
    public function updatePrices(array $commands): PriceUpdateClientResult;
}
