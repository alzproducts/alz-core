<?php

declare(strict_types=1);

namespace App\Application\Mixpanel\UseCases;

use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Mixpanel\ValueObjects\SyncOrdersToMixpanelResult;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHashMatcher;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\MissingRequiredDataException;
use App\Domain\Exceptions\PayloadSerializationException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate order synchronization from local database to Mixpanel.
 *
 * Syncs "Checkout Completed" and "Product Purchased" events for orders
 * not already tracked by the frontend JavaScript SDK. Uses pre-export
 * deduplication via order_id_hashed to prevent duplicates.
 *
 * Fail-fast behavior: If Mixpanel Export API fails or returns empty,
 * the entire sync fails. We cannot proceed without deduplication data.
 */
final readonly class SyncOrdersToMixpanelUseCase
{
    /**
     * Buffer hours to exclude from sync window.
     *
     * Orders in the last 4 hours are excluded because:
     * 1. Frontend may have just tracked them
     * 2. Mixpanel needs time to ingest and make them available via Export API
     */
    private const int INGESTION_BUFFER_HOURS = 4;

    /**
     * Extra hours to query from Mixpanel before the sync window.
     *
     * Catches orders placed just before sync window that may have been tracked late.
     */
    private const int EXPORT_LOOKBACK_HOURS = 24;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private CustomerRepositoryInterface $customerRepository,
        private MixpanelClientInterface $mixpanel,
        private string $analyticsSalt,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize orders from local database to Mixpanel.
     *
     * @param DateTimeImmutable $from Start of date range (inclusive)
     * @param DateTimeImmutable $to End of date range (adjusted internally for ingestion buffer)
     *
     * @return SyncOrdersToMixpanelResult Results with counts of orders processed
     *
     * @throws AuthenticationExpiredException When Mixpanel credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When Mixpanel API unavailable
     * @throws UnexpectedApiResultException When Export API returns empty (fail-safe)
     * @throws PayloadSerializationException When import payload encoding fails
     * @throws DatabaseOperationFailedException When database query fails
     * @throws MissingRequiredDataException When customer trade status data is not available
     */
    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): SyncOrdersToMixpanelResult
    {
        // Apply ingestion buffer only when $to is recent (within buffer hours of now)
        // Historical syncs don't need the buffer - Mixpanel already has the data
        $now = new DateTimeImmutable();
        $bufferCutoff = $now->modify('-' . self::INGESTION_BUFFER_HOURS . ' hours');
        $adjustedTo = ($to > $bufferCutoff) ? $bufferCutoff : $to;

        $this->logger->info('Starting Mixpanel order sync', [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'adjusted_to' => $adjustedTo->format('Y-m-d H:i:s'),
            'buffer_applied' => $to > $bufferCutoff,
        ]);

        // Step 1: Get existing order hashes from Mixpanel (FAIL if this fails)
        $existingHashes = $this->getExistingHashes($from, $to);

        $this->logger->info('Retrieved existing order hashes from Mixpanel', [
            'count' => \count($existingHashes),
        ]);

        // Step 2: Get orders from local database
        $orders = $this->orderRepository->getOrdersInDateRange($from, $adjustedTo);

        if ($orders === []) {
            $this->logger->info('No orders found in date range', [
                'from' => $from->format('Y-m-d H:i:s'),
                'adjusted_to' => $adjustedTo->format('Y-m-d H:i:s'),
            ]);

            return SyncOrdersToMixpanelResult::empty();
        }

        $this->logger->info('Retrieved orders from database', [
            'count' => \count($orders),
        ]);

        // Step 3: Filter out orders already in Mixpanel
        $existingHashSet = \array_flip($existingHashes);
        $ordersToSync = $this->filterNewOrders($orders, $existingHashSet);

        $skippedCount = \max(0, \count($orders) - \count($ordersToSync));

        $this->logger->info('Filtered orders for sync', [
            'total' => \count($orders),
            'to_sync' => \count($ordersToSync),
            'skipped' => $skippedCount,
        ]);

        if ($ordersToSync === []) {
            return new SyncOrdersToMixpanelResult(
                ordersInRange: \count($orders),
                skipped: $skippedCount,
                synced: 0,
                checkoutEventsCreated: 0,
                productEventsCreated: 0,
            );
        }

        // Step 4: Get trade status for customers
        $customerTradeMap = $this->getCustomerTradeMap($ordersToSync);

        // Step 5: Import orders to Mixpanel
        $this->mixpanel->importOrders($ordersToSync, $customerTradeMap);

        $productEventsCount = $this->countProductEvents($ordersToSync);

        $this->logger->info('Mixpanel order sync completed', [
            'orders_synced' => \count($ordersToSync),
            'checkout_events' => \count($ordersToSync),
            'product_events' => $productEventsCount,
            'total_events' => \count($ordersToSync) + $productEventsCount,
        ]);

        return new SyncOrdersToMixpanelResult(
            ordersInRange: \count($orders),
            skipped: $skippedCount,
            synced: \count($ordersToSync),
            checkoutEventsCreated: \count($ordersToSync),
            productEventsCreated: $productEventsCount,
        );
    }

    /**
     * Get existing order hashes from Mixpanel for deduplication.
     *
     * Queries a wider date range (from - 24h to to) to catch edge cases.
     *
     * @return array<string>
     *
     * @throws AuthenticationExpiredException When Mixpanel credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When Mixpanel API unavailable
     * @throws UnexpectedApiResultException When export fails or returns empty
     */
    private function getExistingHashes(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Expand query range to catch orders tracked late
        $exportFrom = $from->modify('-' . self::EXPORT_LOOKBACK_HOURS . ' hours');

        return $this->mixpanel->getExistingOrderHashes($exportFrom, $to);
    }

    /**
     * Filter orders to only those not already in Mixpanel.
     *
     * Uses multi-hash matching to handle frontend hash variations:
     * - SHA-256 vs Legacy Base64 algorithm (browser capability)
     * - Configured salt vs fallback salt (frontend bug)
     *
     * @param list<Order> $orders
     * @param array<string, int|string> $existingHashSet Hash set for O(1) lookup (via array_flip)
     *
     * @return list<Order>
     */
    private function filterNewOrders(array $orders, array $existingHashSet): array
    {
        $newOrders = [];

        foreach ($orders as $order) {
            $exists = OrderAnalyticsHashMatcher::existsInHashes(
                $existingHashSet,
                $order->reference,
                $order->orderPlacedAt,
                $this->analyticsSalt,
            );

            if (!$exists) {
                $newOrders[] = $order;
            }
        }

        return $newOrders;
    }

    /**
     * Get trade status map for customers.
     *
     * @param list<Order> $orders Non-empty list of orders to sync
     *
     * @return array<int, bool> Map of customer ID → is_trade status
     *
     * @throws MissingRequiredDataException When customer not found in local database
     * @throws DatabaseOperationFailedException When database query fails
     */
    private function getCustomerTradeMap(array $orders): array
    {
        $customerIds = \array_values(\array_unique(
            \array_map(static fn(Order $order): int => $order->customer->id, $orders),
        ));

        $tradeMap = $this->customerRepository->getTradeStatusByIds($customerIds);

        // Fail-fast: All customers must exist in local DB
        // If missing, customer sync hasn't run - fail now, reimport later
        $missingIds = \array_diff($customerIds, \array_keys($tradeMap));

        if ($missingIds !== []) {
            throw new MissingRequiredDataException(
                dataType: 'customer trade status',
                operation: 'Mixpanel order sync',
                resolution: \sprintf(
                    'Customer IDs not found in local database: %s. Run customer sync first.',
                    \implode(', ', $missingIds),
                ),
            );
        }

        return $tradeMap;
    }

    /**
     * Count total product events that will be created.
     *
     * @param list<Order> $orders
     *
     * @return int<0, max>
     */
    private function countProductEvents(array $orders): int
    {
        $count = \array_reduce(
            $orders,
            static fn(int $carry, Order $order): int => $carry + ($order->products !== null ? \count($order->products) : 0),
            0,
        );

        return \max(0, $count);
    }
}
