<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Shopwired;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;
use Illuminate\Console\Command;
use ValueError;

/**
 * Audit ShopWired order sync by comparing API data with database.
 *
 * Use this command to diagnose sync issues, identify missing orders/order lines,
 * and verify data integrity between ShopWired API and local database.
 *
 * @example php artisan shopwired:audit-order-sync
 * @example php artisan shopwired:audit-order-sync --from=2025-01-01 --to=2025-01-28
 * @example php artisan shopwired:audit-order-sync --show-missing
 */
final class ShopwiredAuditOrderSyncCommand extends Command
{
    protected $signature = 'shopwired:audit-order-sync
                            {--from= : Start date (Y-m-d), defaults to 30 days ago}
                            {--to= : End date (Y-m-d), defaults to 6 hours ago to allow for sync lag}
                            {--show-missing : Show IDs of missing orders/order lines}
                            {--limit=20 : Limit number of missing items shown}';

    protected $description = 'Compare ShopWired API order/order line counts with database';

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotAvailableException
     * @throws ResourceNotFoundException
     * @throws ValueError
     */
    public function handle(
        OrderClientInterface $orderClient,
        OrderRepositoryInterface $orderRepository,
    ): int {
        $from = $this->parseDate($this->option('from'), (new DateTimeImmutable())->modify('-30 days'));
        // Default to 6 hours ago to allow for sync lag (orders need time to propagate through the system)
        $to = $this->parseDate($this->option('to'), (new DateTimeImmutable())->modify('-6 hours'));

        $this->info("Auditing orders from {$from->format('Y-m-d')} to {$to->format('Y-m-d')}...");

        $this->info('Fetching orders from ShopWired API...');
        $apiOrders = $orderClient->listOrdersInRangeWithDetails($from, $to);

        [$apiOrderIds, $apiOrderLineCount] = $this->extractApiCounts($apiOrders);

        $this->info('Fetching orders from database...');
        // Use unfiltered method for accurate raw comparison with API
        $dbOrders = $orderRepository->getAllOrdersInDateRange($from, $to);

        [$dbOrderIds, $dbOrderLineCount] = $this->extractDbCounts($dbOrders);

        $this->displayComparisonTable($apiOrderIds, $apiOrderLineCount, $dbOrderIds, $dbOrderLineCount);

        $missingOrderIds = \array_diff($apiOrderIds, $dbOrderIds);

        $this->displayMissingSummary($missingOrderIds, $apiOrderLineCount - $dbOrderLineCount);
        $this->displayExtraSummary($dbOrderIds, $apiOrderIds);
        $this->displayMissingDetails($apiOrders, $missingOrderIds);

        $hasDiscrepancy = $missingOrderIds !== [] || $apiOrderLineCount !== $dbOrderLineCount;

        return $hasDiscrepancy ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Parse a date option or return the default.
     *
     * @throws ValueError When setTime receives invalid values (impossible with 0,0,0)
     */
    private function parseDate(?string $dateString, DateTimeImmutable $default): DateTimeImmutable
    {
        if ($dateString === null || $dateString === '') {
            return $default;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateString);

        if ($parsed === false) {
            $this->warn("Invalid date format: {$dateString}, using default");

            return $default;
        }

        return $parsed->setTime(0, 0, 0);
    }

    /**
     * Extract order IDs and line count from API orders.
     *
     * @param list<Order> $apiOrders
     *
     * @return array{list<int>, int} [orderIds, orderLineCount]
     */
    private function extractApiCounts(array $apiOrders): array
    {
        $orderIds = [];
        $orderLineCount = 0;

        foreach ($apiOrders as $order) {
            $orderIds[] = $order->id;
            $orderLineCount += \count($order->products ?? []);
        }

        return [$orderIds, $orderLineCount];
    }

    /**
     * Extract order IDs and line count from database orders.
     *
     * @param list<Order> $dbOrders
     *
     * @return array{list<int>, int} [orderIds, orderLineCount]
     */
    private function extractDbCounts(array $dbOrders): array
    {
        $orderIds = [];
        $orderLineCount = 0;

        foreach ($dbOrders as $order) {
            $orderIds[] = $order->id;
            $orderLineCount += \count($order->products ?? []);
        }

        return [$orderIds, $orderLineCount];
    }

    /**
     * Display the comparison table.
     *
     * @param list<int> $apiOrderIds
     * @param list<int> $dbOrderIds
     */
    private function displayComparisonTable(
        array $apiOrderIds,
        int $apiOrderLineCount,
        array $dbOrderIds,
        int $dbOrderLineCount,
    ): void {
        $orderDiff = \count($apiOrderIds) - \count($dbOrderIds);
        $lineDiff = $apiOrderLineCount - $dbOrderLineCount;

        $this->newLine();
        $this->table(
            ['Source', 'Orders', 'Order Lines'],
            [
                ['API', \count($apiOrderIds), $apiOrderLineCount],
                ['Database', \count($dbOrderIds), $dbOrderLineCount],
                ['Difference', $this->formatDiff($orderDiff), $this->formatDiff($lineDiff)],
            ],
        );
    }

    /**
     * Format a difference value with +/- prefix.
     */
    private function formatDiff(int $diff): string
    {
        if ($diff === 0) {
            return '0';
        }

        return $diff > 0 ? "+{$diff}" : (string) $diff;
    }

    /**
     * Display summary of missing items.
     *
     * @param array<int> $missingOrderIds
     */
    private function displayMissingSummary(array $missingOrderIds, int $orderLineDiff): void
    {
        $this->newLine();
        $this->info('Missing from database:');
        $this->line('  Orders: ' . \count($missingOrderIds));

        if ($orderLineDiff > 0) {
            $this->line("  Order Lines: ~{$orderLineDiff} (estimated based on total diff)");
        }
    }

    /**
     * Display summary of extra orders in database.
     *
     * @param list<int> $dbOrderIds
     * @param list<int> $apiOrderIds
     */
    private function displayExtraSummary(array $dbOrderIds, array $apiOrderIds): void
    {
        $extraOrderIds = \array_diff($dbOrderIds, $apiOrderIds);

        if ($extraOrderIds === []) {
            return;
        }

        $this->newLine();
        $this->warn('Extra in database (deleted from API?):');
        $this->line('  Orders: ' . \count($extraOrderIds));
    }

    /**
     * Display detailed missing items if --show-missing flag is set.
     *
     * @param list<Order> $apiOrders
     * @param array<int> $missingOrderIds
     */
    private function displayMissingDetails(array $apiOrders, array $missingOrderIds): void
    {
        if (!$this->option('show-missing')) {
            return;
        }

        if ($missingOrderIds === []) {
            return;
        }

        $limit = (int) $this->option('limit');

        $this->newLine();
        $this->info('Missing Order IDs (first ' . $limit . '):');

        $orderMap = [];
        foreach ($apiOrders as $order) {
            $orderMap[$order->id] = $order;
        }

        foreach (\array_slice($missingOrderIds, 0, $limit) as $id) {
            $order = $orderMap[$id] ?? null;
            if ($order !== null) {
                $date = $order->orderPlacedAt->format('Y-m-d');
                $total = \number_format($order->total, 2);
                $this->line("  - {$id} | Ref: {$order->reference} | {$date} | £{$total}");
            } else {
                $this->line("  - {$id}");
            }
        }
    }
}
