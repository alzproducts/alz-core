<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\DTOs;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\RequiredWithoutAll;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Per-SKU price update from the HTTP request body.
 *
 * At least one price field (price, sale_price, rrp) must be provided.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class SkuPriceUpdateDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        #[Min(0), RequiredWithoutAll('sale_price', 'rrp')]
        public readonly ?float $price = null,
        #[Min(0), RequiredWithoutAll('price', 'rrp')]
        public readonly ?float $salePrice = null,
        #[Min(0), RequiredWithoutAll('price', 'sale_price')]
        public readonly ?float $rrp = null,
    ) {}

    public function toCommand(): UpdatePriceCommand
    {
        return new UpdatePriceCommand(
            sku: Sku::fromTrusted($this->sku),
            price: $this->price !== null ? Money::inclusive($this->price) : null,
            salePrice: $this->salePrice !== null ? Money::inclusive($this->salePrice) : null,
            rrp: $this->rrp !== null ? Money::inclusive($this->rrp) : null,
        );
    }
}
