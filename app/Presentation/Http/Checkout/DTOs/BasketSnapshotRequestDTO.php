<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\DTOs;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Request validation for POST /api/checkout/snapshot.
 *
 * Wire format: `basket_total` is a numeric string (preserves decimal precision),
 * `delivery_date` is ISO `Y-m-d`, `vat_relief` is a structured object that
 * mirrors ShopWired's VAT-relief declaration form ({@see VatReliefRequestDTO}).
 */
#[MapInputName(SnakeCaseMapper::class)]
final class BasketSnapshotRequestDTO extends Data
{
    public function __construct(
        #[Required, Numeric, StringType]
        public readonly string $basketTotal,
        #[Nullable, StringType, Max(100)]
        public readonly ?string $shippingMethodId = null,
        #[Nullable, StringType, DateFormat('Y-m-d')]
        public readonly ?string $deliveryDate = null,
        #[Nullable, StringType, Max(500)]
        public readonly ?string $giftNote = null,
        public readonly ?VatReliefRequestDTO $vatRelief = null,
    ) {}
}
