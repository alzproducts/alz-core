<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * A fixed edge-case order and the sync outcome it must produce. `superseded` is the cancelled original of an
 * edited order: same reference, lower external ID.
 */
final readonly class EdgeCaseOrder
{
    public function __construct(
        public EdgeCase $case,
        public ExpectedOutcome $expectedOutcome,
        public OrderSpec $order,
        public ?OrderSpec $superseded = null,
    ) {}
}
