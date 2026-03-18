<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\PartialBatchFailureException;

/**
 * Client for batch price updates via POST products/prices.
 *
 * Accepts any number of commands — the client handles chunking into
 * batches of 15 (ShopWired's limit) internally.
 *
 * Hybrid error model (AWS/Google batch pattern + AggregateException):
 * - Per-item failures (updated: false) → included in returned list
 * - Full transport failure (all chunks fail) → throws standard domain exceptions
 * - Partial transport failure (some chunks succeed, some fail) →
 *   returns successful results AND throws PartialBatchFailureException
 *   wrapping the actual domain exceptions from failed chunks
 */
interface PriceUpdateClientInterface
{
    /**
     * @param list<UpdatePriceCommand> $commands Any size — client handles chunking
     *
     * @return list<PriceUpdateItemResult>
     *
     * @throws InvalidApiRequestException All chunks failed (programming error)
     * @throws AuthenticationExpiredException All chunks failed (auth)
     * @throws ExternalServiceUnavailableException All chunks failed (transient)
     * @throws InvalidApiResponseException All chunks failed (contract violation)
     * @throws PartialBatchFailureException Some chunks succeeded, some failed —
     *                                      $failures contains the domain exceptions,
     *                                      return value contains successful results
     */
    public function updatePrices(array $commands): array;
}
