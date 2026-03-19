<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for the GeneralInfo section of a Linnworks order.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderGeneralInfoResponse extends Data
{
    public function __construct(
        public readonly string $referenceNum,
        public readonly string $externalReferenceNum,
        public readonly string $secondaryReferenceNumber,
        public readonly int $status,
        public readonly bool $holdOrCancel,
        public readonly bool $isParked,
        public readonly string $source,
        public readonly string $subSource,
        public readonly string $fulfilmentLocationId,
        public readonly string $location,
        public readonly ?int $marker = null,
        public readonly ?string $despatchByDate = null,
        public readonly ?string $receivedDate = null,
    ) {}
}
