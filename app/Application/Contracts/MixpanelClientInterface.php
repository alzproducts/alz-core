<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use DateTimeImmutable;

interface MixpanelClientInterface
{
    /**
     * Verify connectivity and authentication with Mixpanel API.
     *
     * Makes a lightweight API call to validate service account credentials
     * without modifying any data.
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function verifyConnectivity(): void;

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics. Infrastructure implementation
     * handles internal transformation to Mixpanel event format.
     *
     * @param array<int, CampaignMetrics> $campaigns
     * @param AdSource $source The ad network these campaigns originate from
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded (data integrity issue)
     */
    public function importCampaigns(array $campaigns, AdSource $source): void;

    /**
     * Replace a lookup table with new data.
     *
     * Sends CSV data to Mixpanel Lookup Tables API. The table is identified
     * by $tableKey, which must exist in the implementation's configuration.
     *
     * Note: Mixpanel only supports full replacement (PUT), not incremental updates.
     * Rate limit: 100 calls per 24 hours (hourly syncs recommended).
     *
     * @param string $tableKey Lookup table identifier (e.g., 'utm_campaigns')
     * @param array<int, string> $headers CSV column headers
     * @param array<int, array<int, string>> $rows Pre-transformed data rows
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    public function replaceLookupTable(string $tableKey, array $headers, array $rows): void;

    /**
     * Export existing order hashes from Mixpanel for deduplication.
     *
     * Queries Mixpanel Export API for "Checkout Completed" events in the date range
     * and extracts the `order_id_hashed` property from each event.
     *
     * @param DateTimeImmutable $from Start of date range (inclusive)
     * @param DateTimeImmutable $to End of date range (inclusive)
     *
     * @return array<string> Set of order_id_hashed values from existing events
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws UnexpectedApiResultException When export returns no data (suspicious)
     */
    public function getExistingOrderHashes(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Import orders to Mixpanel as "Checkout Completed" and "Product Purchased" events.
     *
     * Transforms Domain Order objects to Mixpanel event format internally.
     * Generates one "Checkout Completed" event per order and one "Product Purchased"
     * event per product line item.
     *
     * @param array<int, Order> $orders Domain orders with products populated
     * @param array<int, bool> $customerTradeMap Map of customer ID → is_trade status
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded
     */
    public function importOrders(array $orders, array $customerTradeMap): void;
}
