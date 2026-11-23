<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * What a chunk's documents are built from.
 */
final readonly class OrderPreparationDTO
{
    /**
     * @param list<ClaimedOrderDTO> $claimed
     */
    public function __construct(
        public string $realmId,
        public array $claimed,
        public DocumentRunLabelsDTO $labels,
    ) {}
}
