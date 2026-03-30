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
 * Contract for Linnworks purchase-order-related SQL queries.
 *
 * Uses the Dashboards/ExecuteCustomScriptQuery endpoint for direct
 * database access, bypassing API pagination and date limits.
 *
 * @template-pattern Application Contract Interface
 */
interface PurchaseDashboardsClientInterface
{
    /**
     * Retrieve purchase order IDs, optionally filtered by date range.
     *
     * When $defaultLocationOnly is true, restricts results to the default
     * Linnworks location (00000000-0000-0000-0000-000000000000).
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase ASC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getPurchaseOrderIdsByDate(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        bool $defaultLocationOnly = false,
    ): array;

    /**
     * Retrieve all OPEN and PENDING purchase order IDs.
     *
     * When $defaultLocationOnly is true, restricts results to the default
     * Linnworks location (00000000-0000-0000-0000-000000000000).
     *
     * @return list<Guid> Purchase order IDs ordered by DateOfPurchase DESC
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getOpenPendingPurchaseOrderIds(bool $defaultLocationOnly = false): array;
}
