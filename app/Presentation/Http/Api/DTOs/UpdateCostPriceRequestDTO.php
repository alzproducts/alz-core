<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Shared\Money\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Request validation for PUT /api/products/{sku}/cost-price.
 *
 * Accepts a camelCase JSON body: { "costPrice": 5.99, "supplierName": "Acme Corp" }
 */
final class UpdateCostPriceRequestDTO extends Data
{
    public function __construct(
        public readonly float $costPrice,
        public readonly string $supplierName,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'costPrice' => ['required', 'numeric', 'min:0'],
            'supplierName' => ['required', 'string', 'min:1'],
        ];
    }

    /**
     * @throws InvalidSkuException When the SKU format is invalid
     */
    public function toCommand(string $sku): UpdateCostPriceCommand
    {
        return new UpdateCostPriceCommand(
            sku: Sku::fromString($sku),
            costPrice: Money::exclusive($this->costPrice),
            supplierName: $this->supplierName,
        );
    }
}
