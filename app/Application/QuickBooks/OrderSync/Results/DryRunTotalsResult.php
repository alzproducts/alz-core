<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\DryRunOrderDTO;
use App\Application\QuickBooks\OrderSync\Enums\DryRunVerdict;

final readonly class DryRunTotalsResult
{
    private const string UNBUILDABLE = 'unbuildable';

    /**
     * @param list<DryRunOrderDTO> $orders earliest placed first
     */
    public function __construct(
        public array $orders,
    ) {}

    /**
     * @return array<string, int> match, warning and unbuildable, zeros included
     */
    public function counts(): array
    {
        $counts = [DryRunVerdict::Match->value => 0, DryRunVerdict::Warning->value => 0, self::UNBUILDABLE => 0];
        foreach ($this->orders as $order) {
            $counts[$order->result instanceof DryRunVerdict ? $order->result->value : self::UNBUILDABLE]++;
        }

        return $counts;
    }
}
