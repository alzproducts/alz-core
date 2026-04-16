<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\View\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Read-only API projection of an order.
 *
 * Takes scalars matching the SQL view row shape and reconstructs single-column
 * domain types (IntId, Money) itself. Multi-column types (OrderStatus,
 * OrderCustomerSummary) arrive pre-built from OrderViewAssembler because their
 * reconstruction requires Infrastructure concerns (enum coalescing, logging).
 */
final readonly class OrderView
{
    public IntId $id;

    public Money $total;

    /**
     * @param int $externalId ShopWired order ID
     * @param int $reference ShopWired human-readable reference number
     * @param DateTimeImmutable $placedAt Order placement timestamp
     * @param string $total Gross total as a decimal string (preserves decimal(14,6) precision)
     * @param OrderStatus $status Multi-field status VO (id/name/type/sortOrder)
     * @param OrderCustomerSummary $customer Billing contact summary
     */
    public function __construct(
        int $externalId,
        public int $reference,
        public DateTimeImmutable $placedAt,
        string $total,
        public OrderStatus $status,
        public OrderCustomerSummary $customer,
    ) {
        $this->id = IntId::from($externalId);
        $this->total = Money::inclusiveFromString($total);
    }
}
