<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

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
        public ?DateTimeImmutable $dateTime,
        public ?string $forename,
        public ?string $surname,
    ) {}
}
