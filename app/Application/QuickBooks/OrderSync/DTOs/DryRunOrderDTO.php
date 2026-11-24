<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\DryRunVerdict;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use DateTimeImmutable;

/**
 * One reported order. An order that cannot be built has a reason code, and no expected total or difference.
 */
final readonly class DryRunOrderDTO
{
    private function __construct(
        public int $reference,
        public DateTimeImmutable $orderDate,
        public ?DecimalMoney $expectedTotal,
        public DecimalMoney $shopwiredTotal,
        public ?DecimalMoney $difference,
        public DryRunVerdict|SyncReasonCode $result,
    ) {}

    public static function built(int $reference, DateTimeImmutable $orderDate, DecimalMoney $expectedTotal, DecimalMoney $shopwiredTotal): self
    {
        $verdict = $expectedTotal->compare($shopwiredTotal) === 0 ? DryRunVerdict::Match : DryRunVerdict::Warning;

        return new self($reference, $orderDate, $expectedTotal, $shopwiredTotal, $expectedTotal->plus($shopwiredTotal->times(-1)), $verdict);
    }

    public static function unbuildable(int $reference, DateTimeImmutable $orderDate, DecimalMoney $shopwiredTotal, SyncReasonCode $reason): self
    {
        return new self($reference, $orderDate, null, $shopwiredTotal, null, $reason);
    }
}
