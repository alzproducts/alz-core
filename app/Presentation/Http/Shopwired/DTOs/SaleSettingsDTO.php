<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\DTOs;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * Sale metadata from the HTTP request body.
 *
 * Dates are cast to DateTimeImmutable on ingestion so the domain VO
 * receives typed values directly without conversion in the controller.
 *
 * removalReason is cast to SaleRemovalReason automatically by Spatie Data
 * (backed enum casting from string value).
 */
final class SaleSettingsDTO extends Data
{
    public function __construct(
        public readonly string $saleReason,
        public readonly ?string $saleComments,
        #[WithCast(DateTimeInterfaceCast::class)]
        public readonly ?DateTimeImmutable $saleStartDate,
        #[WithCast(DateTimeInterfaceCast::class)]
        public readonly ?DateTimeImmutable $saleEndDate,
        #[Min(0)]
        public readonly ?int $saleEndsStock,
        public readonly ?SaleRemovalReason $removalReason,
    ) {}

    public function toDomain(): SaleSettings
    {
        return new SaleSettings(
            saleReason: $this->saleReason,
            saleComments: $this->saleComments,
            saleStartDate: $this->saleStartDate,
            saleEndDate: $this->saleEndDate,
            saleEndsStock: $this->saleEndsStock,
            removalReason: $this->removalReason,
        );
    }
}
