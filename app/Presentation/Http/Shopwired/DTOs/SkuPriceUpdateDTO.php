<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\DTOs;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Per-SKU price update from the HTTP request body.
 *
 * Uses Spatie Optional to distinguish "not sent" from "sent as null":
 * - Optional (key absent)  → no change
 * - null (key sent as null) → clear the field (salePrice/rrp only)
 * - float (key sent as value) → set the field
 *
 * price cannot be null — if present, must be a valid float ≥ 0.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class SkuPriceUpdateDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        #[Min(0)]
        public readonly Optional|float $price = new Optional(),
        #[Min(0)]
        public readonly Optional|float|null $salePrice = new Optional(),
        #[Min(0)]
        public readonly Optional|float|null $rrp = new Optional(),
    ) {}

    public function toCommand(): UpdatePriceCommand
    {
        return new UpdatePriceCommand(
            sku: Sku::fromTrusted($this->sku),
            price: $this->price instanceof Optional ? null : Money::inclusive($this->price),
            salePrice: match (true) {
                $this->salePrice instanceof Optional => null,
                $this->salePrice === null => Money::inclusive(0),
                default => Money::inclusive($this->salePrice),
            },
            rrp: match (true) {
                $this->rrp instanceof Optional => null,
                $this->rrp === null => Money::inclusive(0),
                default => Money::inclusive($this->rrp),
            },
        );
    }
}
