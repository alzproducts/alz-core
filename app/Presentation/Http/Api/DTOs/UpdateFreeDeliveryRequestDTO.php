<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Request validation for POST /api/products/free-delivery.
 *
 * Accepts a JSON body:
 * {
 *   "updates": [
 *     {"identifier": "SKU123", "type": "Standard"},
 *     {"identifier": 5585518, "type": "Express"}
 *   ]
 * }
 */
final class UpdateFreeDeliveryRequestDTO extends Data
{
    /**
     * @param DataCollection<int, FreeDeliveryUpdateItemDTO> $updates
     */
    public function __construct(
        #[Min(1), Max(1000), DataCollectionOf(FreeDeliveryUpdateItemDTO::class)]
        public readonly DataCollection $updates,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'updates' => ['required', 'array', 'min:1', 'max:1000'],
        ];
    }
}
