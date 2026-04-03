<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\Weight;

/**
 * Inventory enrichment data for a product, sourced from Linnworks.
 *
 * Self-constructing VO — receives raw primitives from StockItemModel and builds
 * domain types internally. The assembler stays thin: it passes raw scalar fields,
 * and this class handles all domain type construction.
 *
 * Available via ?include=inventory on both list and detail product endpoints.
 */
final readonly class ProductInventory
{
    public ?Gtin $barcode;

    public ?Weight $weight;

    public ?Dimensions $dimensions;

    /**
     * @param string|null $barcode Raw barcode string; parsed as GTIN (null on invalid/empty)
     * @param int|null $minimumLevel Minimum stock level (null = no data, 0 = minimum is zero)
     * @param float|null $weight Weight value (null = not set)
     * @param string|null $weightUnit Weight unit backing value (e.g. 'Kilogram', 'Gram')
     * @param float|null $height Height in configured unit (null = not measured)
     * @param float|null $width Width in configured unit (null = not measured)
     * @param float|null $depth Depth in configured unit (null = not measured)
     * @param bool $isComposite Whether this is a composite/bundle item
     * @param string $categoryName Linnworks category name
     */
    public function __construct(
        ?string $barcode,
        public ?int $minimumLevel,
        ?float $weight,
        ?string $weightUnit,
        ?float $height,
        ?float $width,
        ?float $depth,
        public bool $isComposite,
        public string $categoryName,
    ) {
        $this->barcode = Gtin::tryFromString($barcode);
        $this->weight = self::parseWeight($weight, $weightUnit);
        $this->dimensions = self::parseDimensions($height, $width, $depth);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'barcode' => $this->barcode?->value,
            'minimum_level' => $this->minimumLevel,
            'weight' => $this->weight !== null ? [
                'value' => $this->weight->value,
                'unit' => $this->weight->unit->value,
            ] : null,
            'dimensions' => $this->dimensions !== null ? [
                'height' => $this->dimensions->height,
                'width' => $this->dimensions->width,
                'depth' => $this->dimensions->depth,
            ] : null,
            'is_composite' => $this->isComposite,
            'category_name' => $this->categoryName,
        ];
    }

    private static function parseWeight(?float $weight, ?string $weightUnit): ?Weight
    {
        if ($weight === null) {
            return null;
        }

        $unit = $weightUnit !== null ? WeightUnit::tryFrom($weightUnit) : null;

        return new Weight($weight, $unit ?? WeightUnit::Kilogram);
    }

    private static function parseDimensions(?float $height, ?float $width, ?float $depth): ?Dimensions
    {
        if ($height === null && $width === null && $depth === null) {
            return null;
        }

        return new Dimensions($height ?? 0.0, $width ?? 0.0, $depth ?? 0.0);
    }
}
