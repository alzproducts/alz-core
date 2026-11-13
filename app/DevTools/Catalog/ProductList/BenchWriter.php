<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

/**
 * One background writer's schedule. A null interval runs the write once, after the start delay.
 */
final readonly class BenchWriter
{
    public function __construct(
        public BenchWriteKind $kind,
        public ?float $intervalSeconds,
        public float $startDelaySeconds,
    ) {}

    public function describe(): string
    {
        $schedule = $this->intervalSeconds === null ? 'once' : "every {$this->intervalSeconds}s";

        return "{$this->kind->value}: {$schedule}, first after {$this->startDelaySeconds}s";
    }
}
