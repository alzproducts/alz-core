<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Provider for lookup table data synchronization.
 *
 * Implementations fetch data from a source (Google Ads, Shopwired, etc.)
 * and transform it to a tabular format for external analytics systems.
 *
 * This enables the Strategy pattern: a single SyncLookupTableUseCase
 * can work with any provider (campaigns, products, SKUs, etc.) without
 * knowing the specifics of data retrieval or transformation.
 */
interface LookupTableProviderInterface
{
    /**
     * Unique identifier for this table (e.g., 'utm_campaigns', 'products').
     *
     * Must match keys in external service configuration (e.g., MixpanelConfig::$lookupTableIds).
     */
    public function getTableKey(): string;

    /**
     * Data source name for logging (e.g., 'Google Ads', 'Shopwired').
     */
    public function getSourceName(): string;

    /**
     * Column headers for the lookup table.
     *
     * @return list<string>
     */
    public function getHeaders(): array;

    /**
     * Fetch data from source AND transform to rows.
     *
     * Each row is an array of strings matching the headers order.
     *
     * @return array<int, array<int, string>>
     *
     * @throws ExternalServiceUnavailableException When the data source is unavailable
     */
    public function fetchRows(): array;
}
