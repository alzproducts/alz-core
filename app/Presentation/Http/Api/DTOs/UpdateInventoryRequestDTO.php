<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class UpdateInventoryRequestDTO extends Data
{
    /**
     * @param DataCollection<int, UpdateInventoryItemDTO> $items
     */
    public function __construct(
        #[Min(1), Max(25), DataCollectionOf(UpdateInventoryItemDTO::class)]
        public readonly DataCollection $items,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'items.*.sku' => ['distinct'],
        ];
    }
}
