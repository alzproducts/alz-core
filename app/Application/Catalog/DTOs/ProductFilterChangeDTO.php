<?php

declare(strict_types=1);

namespace App\Application\Catalog\DTOs;

use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\ValueObjects\IntId;

final readonly class ProductFilterChangeDTO
{
    /**
     * @param list<RatingFilterValue> $desiredFilterValues
     */
    public function __construct(
        public IntId $productId,
        public int $optionNo,
        public array $desiredFilterValues,
    ) {}

    /**
     * Create from the raw values returned by the rating filter view.
     *
     * @throws InvalidEnumValueException
     */
    public static function fromViewRow(int $productId, string $desiredFilterValues, int $optionNo): self
    {
        return new self(
            productId: IntId::from($productId),
            optionNo: $optionNo,
            desiredFilterValues: RatingFilterValue::fromPostgresArray($desiredFilterValues),
        );
    }

    /** @return list<RatingFilterValue>|null Filter values to set, or null to remove the filter */
    public function filterValuesForDispatch(): ?array
    {
        return $this->desiredFilterValues === [] ? null : $this->desiredFilterValues;
    }
}
