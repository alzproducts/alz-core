<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;

/**
 * Linnworks purchase order note value object.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderNote
{
    public function __construct(
        public Guid $pkPurchaseId,
        public string $note,
        public ?string $dateTime,
        public ?string $forename,
        public ?string $surname,
    ) {}
}
