<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Contract for Linnworks order-related SQL queries.
 *
 * Uses the Dashboards/ExecuteCustomScriptQuery endpoint for direct
 * database access, enabling queries that bypass the v2 GetOrders API's
 * ~30-day fromDate limit.
 *
 * @template-pattern Application Contract Interface
 */
interface OrderDashboardsClientInterface
{
    /**
     * Retrieve all processed order IDs, optionally filtered by date range.
     *
     * When both $from and $to are provided, filters by received date.
     * When both are null, returns all processed order IDs.
     *
     * @return list<Guid> Order IDs ordered by received date ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getProcessedOrderIdsByOrderDate(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;
}
