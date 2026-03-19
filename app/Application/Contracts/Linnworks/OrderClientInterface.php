<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Generator;

/**
 * Contract for Linnworks orders API client.
 *
 * Provides access to the v2 GetOrders endpoint for processed orders.
 * Token pagination handled internally — yields batches via Generator.
 */
interface OrderClientInterface
{
    /**
     * Iterate processed orders updated since fromDate in batches.
     *
     * Token pagination handled internally. Yields batches of ~200 orders.
     * Same pattern as InventoryClientInterface::iterateStockItemBatches().
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function iterateProcessedOrders(DateTimeImmutable $fromDate): Generator;

    /**
     * Fetch a single order by Linnworks order ID.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When order not found
     */
    public function getOrderById(Guid $orderId): LinnworksOrder;
}
