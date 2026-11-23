<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * A sync-source order: the builder's input, or the defect that keeps it from being one.
 */
final readonly class SourceOrderDTO
{
    public function __construct(
        public int $reference,
        public int $externalId,
        public DateTimeImmutable $placedAt,
        public OrderLifecycleStatus $status,
        public ?SyncOrderDTO $order,
        public ?SourceOrderDefect $defect,
    ) {
        Assert::true(($order === null) !== ($defect === null), 'A source order has an order or a defect, never both');
    }
}
