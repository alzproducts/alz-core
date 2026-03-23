<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\DTOs;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Per-SKU price update from the HTTP request body.
 */
final class SkuPriceUpdateDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        #[Min(0)]
        public readonly ?float $price,
        #[Min(0)]
        public readonly ?float $salePrice,
    ) {}

    public function toCommand(): UpdatePriceCommand
    {
        return new UpdatePriceCommand(
            sku: Sku::fromTrusted($this->sku),
            price: $this->price !== null ? Money::inclusive($this->price) : null,
            salePrice: $this->salePrice !== null ? Money::inclusive($this->salePrice) : null,
        );
    }
}
