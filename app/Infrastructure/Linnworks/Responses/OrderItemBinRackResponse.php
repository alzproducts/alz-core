<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Override;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for order item bin rack entries.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderItemBinRackResponse extends Data
{
    public function __construct(
        public readonly string $location,
        public readonly string $binRack,
        public readonly ?int $batchId,
        public readonly ?int $orderItemBatchId,
        public readonly int $quantity,
        public readonly ?string $addedDate,
    ) {}

    /**
     * @return array{location: string, binRack: string, batchId: ?int, orderItemBatchId: ?int, quantity: int, addedDate: ?string}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'binRack' => $this->binRack,
            'batchId' => $this->batchId,
            'orderItemBatchId' => $this->orderItemBatchId,
            'quantity' => $this->quantity,
            'addedDate' => $this->addedDate,
        ];
    }
}
