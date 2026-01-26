<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\PayloadSerializationException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Mixpanel\DTOs\MixpanelAdSpendEventDTO;
use App\Infrastructure\Mixpanel\DTOs\MixpanelCheckoutCompletedDTO;
use App\Infrastructure\Mixpanel\DTOs\MixpanelProductPurchasedDTO;
use App\Infrastructure\Support\CsvFormatter;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Manages Mixpanel API interactions for events and lookup tables.
 *
 * Responsibilities:
 * 1. Transform Domain objects to API format
 * 2. Delegate HTTP operations to transport layer
 * 3. Construct API endpoints using configuration
 *
 * HTTP concerns (auth, retry, exception translation) are handled by MixpanelHttpTransport.
 */
final readonly class MixpanelClient implements MixpanelClientInterface
{
    /**
     * Date when frontend JavaScript SDK started tracking "Checkout Completed" events.
     *
     * Before this date, Mixpanel may return empty results until a historical backfill is run.
     * After this date, empty results indicate potential frontend tracking issues.
     */
    private const string FRONTEND_TRACKING_START_DATE = '2025-10-01';

    public function __construct(
        private MixpanelHttpTransport $transport,
        private MixpanelConfig $config,
    ) {}

    /**
     * Verify connectivity and authentication with Mixpanel API.
     *
     * Calls the /api/app/me endpoint to validate service account credentials.
     * Uses fail-fast approach (no retry) to detect credential issues immediately.
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function verifyConnectivity(): void
    {
        $this->transport->request(
            method: 'GET',
            url: MixpanelConfig::MAIN_API_URL . '/api/app/me',
            retry: false,
        );
    }

    /**
     * Import campaign metrics to Mixpanel analytics.
     *
     * Accepts Domain layer campaign metrics and internally transforms
     * them to Infrastructure DTO for Mixpanel API formatting.
     *
     * @param array<int, CampaignMetrics> $campaigns Domain campaign metrics
     * @param AdSource $source The ad network these campaigns originate from
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded
     */
    public function importCampaigns(array $campaigns, AdSource $source): void
    {
        if (\count($campaigns) === 0) {
            return;
        }

        $payload = \array_map(
            static fn(CampaignMetrics $campaign): array => MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign, $source)->toMixpanelFormat(),
            $campaigns,
        );

        $this->transport->request(
            method: 'POST',
            url: "{$this->config->dataApiBaseUrl}/import?project_id={$this->config->projectId}",
            body: $this->encodeJson($payload),
            contentType: 'application/json',
        );
    }

    /**
     * Replace a lookup table with new data.
     *
     * Sends CSV data to Mixpanel Lookup Tables API. The table is identified
     * by $tableKey, which must exist in MixpanelConfig::$lookupTableIds.
     *
     * Note: Mixpanel only supports full replacement (PUT), not incremental updates.
     * Rate limit: 100 calls per 24 hours (hourly syncs recommended).
     *
     * @param string $tableKey Key in MixpanelConfig::$lookupTableIds (e.g., 'utm_campaigns')
     * @param array<int, string> $headers CSV column headers
     * @param array<int, array<int, string>> $rows Pre-transformed data rows
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidConfigurationException When tableKey is not configured
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     */
    public function replaceLookupTable(string $tableKey, array $headers, array $rows): void
    {
        if (!\array_key_exists($tableKey, $this->config->lookupTableIds)) {
            throw new InvalidConfigurationException(
                "mixpanel.lookup_table_ids.{$tableKey}",
                "Unknown lookup table key: {$tableKey}. Available keys: " . \implode(', ', \array_keys($this->config->lookupTableIds)),
            );
        }

        $tableId = $this->config->lookupTableIds[$tableKey];
        $csv = CsvFormatter::format($headers, $rows);

        $this->transport->request(
            method: 'PUT',
            url: "{$this->config->dataApiBaseUrl}/lookup-tables/{$tableId}?project_id={$this->config->projectId}",
            body: $csv,
            contentType: 'text/csv',
        );
    }

    /**
     * Export existing order hashes from Mixpanel for deduplication.
     *
     * Queries Mixpanel Export API for "Checkout Completed" events in the date range
     * and extracts the `order_id_hashed` property from each event.
     *
     * JSONL Response Format: Each line is a JSON object:
     * {"event":"Checkout Completed","properties":{"order_id_hashed":"abc123...",...}}
     *
     * @param DateTimeImmutable $from Start of date range (inclusive)
     * @param DateTimeImmutable $to End of date range (inclusive)
     *
     * @return list<string> Set of order_id_hashed values from existing events
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws UnexpectedApiResultException When export returns no data (suspicious - could mask API issues)
     */
    public function getExistingOrderHashes(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Static event filter - JSON encoding cannot fail for this simple array
        $eventFilter = '["Checkout Completed"]';

        $queryParams = \http_build_query([
            'project_id' => $this->config->projectId,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            'event' => $eventFilter,
        ]);

        $response = $this->transport->request(
            method: 'GET',
            url: "{$this->config->exportApiBaseUrl}/api/2.0/export?{$queryParams}",
        );

        $body = $response->body();

        // Empty body with 200 OK means no events in range
        if (\mb_trim($body) === '') {
            $frontendTrackingStart = new DateTimeImmutable(self::FRONTEND_TRACKING_START_DATE);

            // Before frontend tracking existed, empty results are expected for historical backfills
            if ($to < $frontendTrackingStart) {
                Log::info('Mixpanel export returned empty response - expected for pre-frontend-tracking period', [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'frontend_tracking_start' => self::FRONTEND_TRACKING_START_DATE,
                ]);

                return [];
            }

            // After frontend tracking started, empty results are suspicious
            // Exception: allowEmptyExport enables bootstrap sync for fresh accounts
            if ($this->config->allowEmptyExport) {
                Log::warning('Mixpanel export returned empty response - proceeding due to allowEmptyExport', [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                ]);

                return [];
            }

            Log::warning('Mixpanel export returned empty response', [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);

            throw new UnexpectedApiResultException(
                'Mixpanel',
                'Export returned empty result - cannot proceed without deduplication data',
            );
        }

        return $this->parseJsonlOrderHashes($body);
    }

    /**
     * Import orders to Mixpanel as "Checkout Completed" and "Product Purchased" events.
     *
     * Transforms Domain Order objects to Mixpanel event format.
     * Each order produces:
     * - 1 "Checkout Completed" event
     * - N "Product Purchased" events (one per product line item)
     *
     * Uses deterministic $insert_id for idempotent imports.
     *
     * @param array<int, Order> $orders Domain orders with products populated
     * @param array<int, bool> $customerTradeMap Map of customer ID → is_trade status
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When API unavailable or request fails
     * @throws PayloadSerializationException When payload cannot be encoded
     */
    public function importOrders(array $orders, array $customerTradeMap): void
    {
        if (\count($orders) === 0) {
            return;
        }

        $events = $this->buildOrderEvents($orders, $customerTradeMap);

        if (\count($events) === 0) {
            return;
        }

        $this->transport->request(
            method: 'POST',
            url: "{$this->config->dataApiBaseUrl}/import?project_id={$this->config->projectId}",
            body: $this->encodeJson($events),
            contentType: 'application/json',
        );
    }

    /**
     * Build Mixpanel events for all orders.
     *
     * @param array<int, Order> $orders
     * @param array<int, bool> $customerTradeMap
     *
     * @return list<array<string, mixed>>
     */
    private function buildOrderEvents(array $orders, array $customerTradeMap): array
    {
        $events = [];

        foreach ($orders as $order) {
            $isBusinessUser = $customerTradeMap[$order->customer->id] ?? false;

            // 1. Checkout Completed event (one per order)
            $checkoutDto = MixpanelCheckoutCompletedDTO::fromOrder(
                $order,
                $this->config->analyticsSalt,
                $isBusinessUser,
            );
            $events[] = $checkoutDto->toMixpanelFormat();

            // 2. Product Purchased events (one per product)
            if ($order->products !== null) {
                foreach ($order->products as $product) {
                    $productDto = MixpanelProductPurchasedDTO::fromOrderProduct(
                        $order,
                        $product,
                        $this->config->analyticsSalt,
                        $isBusinessUser,
                    );
                    $events[] = $productDto->toMixpanelFormat();
                }
            }
        }

        return $events;
    }

    /**
     * Encode payload as JSON with exception translation.
     *
     * @param array<int, array<string, mixed>> $payload
     *
     * @throws PayloadSerializationException When payload cannot be encoded (data integrity issue)
     */
    private function encodeJson(array $payload): string
    {
        try {
            return \json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('Mixpanel payload encoding failed', [
                'error' => $e->getMessage(),
            ]);

            throw new PayloadSerializationException('Mixpanel', $e->getMessage(), $e);
        }
    }

    /**
     * Parse JSONL response and extract order_id_hashed values.
     *
     * Mixpanel Export API returns newline-delimited JSON (JSONL).
     * Each line: {"event":"...","properties":{"order_id_hashed":"...",...}}
     *
     * @return list<string> Unique order_id_hashed values
     *
     * @throws UnexpectedApiResultException When JSONL cannot be parsed, structure unexpected,
     *                                      or events are missing required order_id_hashed property
     */
    private function parseJsonlOrderHashes(string $jsonl): array
    {
        $lines = \explode("\n", \mb_trim($jsonl));
        $hashes = [];
        $eventsProcessed = 0;
        $eventsMissingHash = 0;

        foreach ($lines as $lineNumber => $line) {
            $line = \mb_trim($line);

            if ($line === '') {
                continue;
            }

            try {
                /** @var array{properties?: array{order_id_hashed?: string}} $event */
                $event = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Log::error('Mixpanel export JSONL parse error', [
                    'line' => $lineNumber + 1,
                    'content' => \mb_substr($line, 0, 100),
                    'error' => $e->getMessage(),
                ]);

                throw new UnexpectedApiResultException(
                    'Mixpanel',
                    "Invalid JSONL on line {$lineNumber}: {$e->getMessage()}",
                    $e,
                );
            }

            $eventsProcessed++;
            $orderHash = $event['properties']['order_id_hashed'] ?? null;

            if (!\is_string($orderHash) || $orderHash === '') {
                $eventsMissingHash++;
                Log::error('Mixpanel event missing order_id_hashed property', [
                    'line' => $lineNumber + 1,
                    'event_properties' => \array_keys($event['properties'] ?? []),
                ]);

                continue;
            }

            $hashes[$orderHash] = true; // Use array keys for deduplication
        }

        // Fail if we processed events but none had valid order_id_hashed
        // This indicates frontend tracking is broken and needs immediate attention
        if ($eventsProcessed > 0 && $hashes === []) {
            Log::critical('All Mixpanel Checkout Completed events missing order_id_hashed', [
                'events_processed' => $eventsProcessed,
                'events_missing_hash' => $eventsMissingHash,
            ]);

            throw new UnexpectedApiResultException(
                'Mixpanel',
                "Processed {$eventsProcessed} Checkout Completed events but none had valid order_id_hashed. "
                . 'Frontend tracking may be broken — investigate immediately.',
            );
        }

        return \array_keys($hashes);
    }
}
