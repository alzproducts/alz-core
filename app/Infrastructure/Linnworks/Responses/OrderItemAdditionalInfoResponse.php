<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Override;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Nested DTO for order item additional info entries.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderItemAdditionalInfoResponse extends Data
{
    public function __construct(
        public readonly string $optionId,
        public readonly string $property,
        public readonly string $value,
    ) {}

    /**
     * @return array{optionId: string, property: string, value: string}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'optionId' => $this->optionId,
            'property' => $this->property,
            'value' => $this->value,
        ];
    }
}
