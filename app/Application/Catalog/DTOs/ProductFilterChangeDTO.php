<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\ValueObjects\IntId;
use BackedEnum;

final readonly class ProductFilterChangeDTO
{
    /**
     * @param list<ShopwiredFilterValueInterface&BackedEnum> $desiredFilterValues
     */
    public function __construct(
        public IntId $productId,
        public int $optionNo,
        public array $desiredFilterValues,
    ) {}

    /** @return list<ShopwiredFilterValueInterface&BackedEnum>|null Filter values to set, or null to remove the filter */
    public function filterValuesForDispatch(): ?array
    {
        return $this->desiredFilterValues === [] ? null : $this->desiredFilterValues;
    }
}
