<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ProductSlice;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxType;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Shopwired\Factories\CustomFieldValueFactory;
use Database\Seeders\ProductSlice\SliceTables;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The slice carries at least one of each eval trap (AC8).
 */
#[CoversNothing]
final class ProductSliceTrapsTest extends TestCase
{
    #[Test]
    public function two_products_share_a_title(): void
    {
        $titles = \array_count_values(SliceFixture::column(SliceFixture::rows()->rows(SliceTables::PRODUCTS), 'title'));

        $this->assertGreaterThanOrEqual(2, \max($titles));
    }

    #[Test]
    public function a_product_has_no_variations(): void
    {
        $slice = SliceFixture::rows();
        $withVariations = SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'product_external_id');
        $products = SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'external_id');

        $this->assertNotEmpty(\array_diff($products, $withVariations));
    }

    #[Test]
    public function a_variation_has_no_matching_stock_item(): void
    {
        $slice = SliceFixture::rows();
        $itemNumbers = \array_flip(SliceFixture::column($slice->rows(SliceTables::STOCK_ITEMS), 'item_number'));
        $unmatched = \array_filter(
            SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'sku'),
            static fn(?string $sku): bool => $sku !== null && !isset($itemNumbers[$sku]),
        );

        $this->assertNotEmpty($unmatched);
    }

    #[Test]
    public function a_stock_item_has_no_supplier_link(): void
    {
        $slice = SliceFixture::rows();
        $linked = SliceFixture::column($slice->rows(SliceTables::STOCK_ITEM_SUPPLIERS), 'stock_item_id');

        $this->assertNotEmpty(\array_diff(SliceFixture::column($slice->rows(SliceTables::STOCK_ITEMS), 'stock_item_id'), $linked));
    }

    #[Test]
    public function zero_prices_and_costs_become_null_through_non_zero_or_null(): void
    {
        $slice = SliceFixture::rows();
        $amounts = [
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'price'),
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCTS), 'cost_price'),
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'price'),
            ...SliceFixture::column($slice->rows(SliceTables::PRODUCT_VARIATIONS), 'cost_price'),
        ];
        $zeros = \array_filter($amounts, static fn(?float $amount): bool => $amount === 0.0);

        $this->assertNotEmpty($zeros);
        $this->assertNull(Money::nonZeroOrNull(\reset($zeros), TaxType::Inclusive));
    }

    #[Test]
    public function a_sku_and_a_gtin_carry_surrounding_whitespace(): void
    {
        $products = SliceFixture::rows()->rows(SliceTables::PRODUCTS);
        $untrimmed = static fn(?string $value): bool => $value !== null && $value !== \mb_trim($value);

        $this->assertNotEmpty(\array_filter(SliceFixture::column($products, 'sku'), $untrimmed));
        $this->assertNotEmpty(\array_filter(SliceFixture::column($products, 'gtin'), $untrimmed));
    }

    #[Test]
    public function a_price_has_more_than_two_decimal_places(): void
    {
        $prices = SliceFixture::column(SliceFixture::rows()->rows(SliceTables::PRODUCTS), 'price');

        $this->assertNotEmpty(\array_filter($prices, static fn(float $price): bool => \round($price, 2) !== $price));
    }

    #[Test]
    public function product_and_category_custom_fields_type_through_seeded_definitions(): void
    {
        $slice = SliceFixture::rows();
        $definitions = self::definitionsByItemAndName($slice->rows(SliceTables::CUSTOM_FIELD_DEFINITIONS));
        $products = $slice->rows(SliceTables::PRODUCTS);
        $categories = $slice->rows(SliceTables::CATEGORIES);

        $freeDelivery = \array_filter(SliceFixture::column(SliceFixture::column($products, 'custom_fields'), 'free_delivery'));
        $this->assertEqualsCanonicalizing(['Standard', 'Express'], \array_values(\array_unique($freeDelivery)));
        $this->assertSame(CustomFieldType::List, $definitions['product']['free_delivery']->base->type);
        $this->assertSame(17, \count(\array_filter(SliceFixture::column(SliceFixture::column($categories, 'custom_fields'), 'is_main_category'))));
        $this->assertSame(CustomFieldType::Toggle, $definitions['category']['is_main_category']->base->type);

        foreach (['product' => $products, 'category' => $categories] as $itemType => $rows) {
            foreach ($rows as $row) {
                foreach ($row['custom_fields'] as $name => $value) {
                    $this->assertArrayHasKey($name, $definitions[$itemType], $itemType . ' custom field without a definition: ' . $name);
                    $typed = CustomFieldValueFactory::createTypedValueFromDefinition($definitions[$itemType][$name], $value);

                    if ($name === 'free_delivery') {
                        $this->assertInstanceOf(StringCustomFieldValue::class, $typed);
                        $this->assertTrue($definitions[$itemType][$name]->base->isValueAllowed($value));
                    }

                    if ($name === 'is_main_category') {
                        $this->assertInstanceOf(ToggleCustomFieldValue::class, $typed);
                    }
                }
            }
        }
    }

    /**
     * Every seeded definition must also construct as the Domain definition (name length, allowed values).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, ConfiguredFieldDefinition>>
     */
    private static function definitionsByItemAndName(array $rows): array
    {
        $definitions = [];

        foreach ($rows as $row) {
            $definitions[$row['item_type']][$row['name']] = new ConfiguredFieldDefinition(
                Uuid::fromTrusted($row['id']),
                new CustomFieldDefinition(
                    $row['external_id'],
                    $row['name'],
                    CustomFieldType::from($row['type']),
                    $row['label'],
                    CustomFieldItemType::from($row['item_type']),
                    $row['sort_order'],
                    $row['allowed_values'],
                ),
                null,
                null,
            );
        }

        return $definitions;
    }
}
