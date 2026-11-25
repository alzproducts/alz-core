<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderTotalsQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DryRunOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderTotalsResolver;
use App\Application\QuickBooks\OrderSync\Results\DryRunTotalsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreparationService;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Compares each order's expected document total with its ShopWired total. intentional: no QuickBooks settings, realm,
 * token or posting dependency, each of which throws on blank credentials (ADR-0015).
 */
final readonly class ReportDryRunTotalsUseCase
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private OrderTotalsQueryRepositoryInterface $totals,
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncPreparationService $preparation,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param DateTimeImmutable $from the first Europe/London day
     * @param DateTimeImmutable $to   the last Europe/London day
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): DryRunTotalsResult
    {
        $this->logger->info('Reporting QuickBooks dry-run totals', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);

        [$start, $before] = OrderSyncCalendarResolver::rangeBounds($from, $to);
        $reported = \array_values(\array_filter(
            $this->totals->placedIn($start, $before),
            static fn(SourceOrderTotalsDTO $order): bool => $order->status !== OrderLifecycleStatus::Cancelled,
        ));

        $orders = [];
        foreach (\array_chunk($reported, self::CHUNK_SIZE) as $chunk) {
            \array_push($orders, ...$this->reportChunk($chunk));
        }
        $result = new DryRunTotalsResult($orders);

        $this->logger->info('Reported QuickBooks dry-run totals', ['counts' => $result->counts()]);

        return $result;
    }

    /**
     * @param list<SourceOrderTotalsDTO> $chunk
     *
     * @return list<DryRunOrderDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function reportChunk(array $chunk): array
    {
        $rows = $this->sourceOrders->findByReferences(\array_map(static fn(SourceOrderTotalsDTO $order): int => $order->reference, $chunk));

        $orders = [];
        foreach ($chunk as $totals) {
            $row = $rows[$totals->reference] ?? null;
            if ($row !== null) {
                $orders[] = $this->report($totals, $row);
            }
        }

        return $orders;
    }

    private function report(SourceOrderTotalsDTO $totals, SourceOrderRowDTO $row): DryRunOrderDTO
    {
        $orderDate = OrderSyncCalendarResolver::documentDate($row->createdAt);
        $shopwiredTotal = OrderTotalsResolver::shopwiredTotal($totals);
        $built = $this->preparation->buildRow($row, DocumentRunLabelsDTO::none(), null);

        return $built instanceof SyncFailureDTO
            ? DryRunOrderDTO::unbuildable($totals->reference, $orderDate, $shopwiredTotal, $built->code)
            : DryRunOrderDTO::built($totals->reference, $orderDate, OrderTotalsResolver::expectedTotal($built->core), $shopwiredTotal);
    }
}
