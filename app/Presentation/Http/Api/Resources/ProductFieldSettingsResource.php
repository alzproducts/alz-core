<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin ProductFieldSettings
 */
final class ProductFieldSettingsResource extends JsonResource
{
    public function __construct(?ProductFieldSettings $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ProductFieldSettings|null $settings */
        $settings = $this->resource;

        return [
            'stock_item_update_mode' => $settings?->stockItemUpdateMode?->value,
        ];
    }
}
