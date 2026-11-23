<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

final readonly class OrderStatusSummaryDTO
{
    /**
     * @param list<string> $unpaidOrderReferences Oldest order first
     */
    public function __construct(
        public int $pendingPaidCount,
        public array $unpaidOrderReferences,
        public int $erroredPaidCount,
    ) {}
}
