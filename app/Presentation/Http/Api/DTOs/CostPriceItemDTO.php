<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Shared\Money\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Per-item DTO for bulk cost price update.
 *
 * Accepts: { "sku": "ABC-123", "costPrice": 5.99 }
 */
final class CostPriceItemDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly float $costPrice,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'min:1'],
            'costPrice' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @throws InvalidSkuException When the SKU format is invalid
     */
    public function toCommand(): UpdateCostPriceCommand
    {
        return new UpdateCostPriceCommand(
            sku: Sku::fromString($this->sku),
            costPrice: Money::exclusive($this->costPrice),
        );
    }
}
