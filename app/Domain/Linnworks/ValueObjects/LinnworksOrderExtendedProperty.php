<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Linnworks order extended property value object.
 *
 * Extended properties are key-value metadata pairs attached to orders
 * in Linnworks. Each has a stable RowId for upsert operations.
 *
 * @template-pattern Domain Value Object
 */
final readonly class LinnworksOrderExtendedProperty
{
    public function __construct(
        public Guid $rowId,
        public string $name,
        public string $value,
        public string $type,
        public ?DateTimeImmutable $createDate,
        public ?DateTimeImmutable $lastUpdate,
        public string $updatedBy,
    ) {}
}
