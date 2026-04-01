<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Request validation for PUT /api/products/cost-prices.
 *
 * Accepts a JSON body:
 * {
 *   "supplierName": "Acme Corp",
 *   "items": [
 *     { "sku": "ABC-123", "costPrice": 5.99 },
 *     { "sku": "DEF-456", "costPrice": 12.50 }
 *   ]
 * }
 */
final class UpdateCostPricesRequestDTO extends Data
{
    /**
     * @param DataCollection<int, CostPriceItemDTO> $items
     */
    public function __construct(
        public readonly string $supplierName,
        #[Min(1), Max(100), DataCollectionOf(CostPriceItemDTO::class)]
        public readonly DataCollection $items,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'supplierName' => ['required', 'string', 'min:1'],
        ];
    }
}
