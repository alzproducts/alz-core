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
        public readonly int $status,
        public readonly bool $isCancelled,
        public readonly string $source,
        public readonly string $subSource,
        public readonly ?string $secondaryReference = null,
        public readonly ?bool $holdOrCancel = null, // Not observed in v2 processed orders — may be v1 only
        public readonly ?bool $isParked = null, // Present in open orders, not confirmed in processed
        public readonly ?string $location = null, // Not observed in v2 — may be v1 only
        public readonly ?int $marker = null,
        public readonly ?string $despatchByDate = null,
        public readonly ?string $receivedDate = null,
    ) {}
}
