<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\ProductRowFactory;
use Database\Seeders\ProductSlice\SeedIds;
use stdClass;

final readonly class ProductRowBuilder
{
    public const int ON_SALE_FILTER_SLOT = 14;

    public const int VAT_RELIEF_FILTER_SLOT = 2;

    public const int SHIPPING_OFFERS_FILTER_SLOT = 20;

    private const int GTIN_PREFIX = 50;

    private ProductRowFactory $factory;

    public function __construct(
        private SeedRandom $random,
        private ProductRoles $roles,
        private CategoryTree $categories,
    ) {
        $this->factory = ProductRowFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    public function row(int $position, ?string $title): array
    {
        $externalId = SeedIds::PRODUCT_BASE + $position;
        $price = $this->price($position);
        $timestamp = $this->random->timestamp();
        $overrides = [
            'external_id' => $externalId,
            'sku' => $this->sku($position),
            'price' => $price,
            'cost_price' => isset($this->roles->zeroCost[$position]) ? 0.0 : $this->random->money(50, 20_000),
            'sale_price' => isset($this->roles->masterSale[$position]) ? \round($price * 0.8, 2) : 0.0,
            'compare_price' => isset($this->roles->comparePrice[$position]) ? \round($price * 1.25 + 1, 2) : null,
            'is_active' => isset($this->roles->active[$position]),
            'vat_exclusive' => isset($this->roles->vatExclusive[$position]),
            'vat_relief' => isset($this->roles->vatRelief[$position]),
            'gtin' => $this->gtin($position),
            'category_ids' => $this->categoryIds($position),
            'images' => $this->images($externalId, $this->roles->imageCounts[$position] ?? 0),
            'custom_fields' => $this->customFields($position),
            'filters' => $this->filters($position),
            'shopwired_created_at' => $timestamp,
            'shopwired_updated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        return $this->factory->row($title === null ? $overrides : [...$overrides, 'title' => $title]);
    }

    /**
     * The SKU as stored, which may carry the whitespace trap.
     */
    public function sku(int $position): ?string
    {
        if (isset($this->roles->nullSku[$position])) {
            return null;
        }

        $sku = SyntheticCodes::productSku($position);

        return $position === $this->roles->whitespaceSku ? ' ' . $sku . ' ' : $sku;
    }

    private function price(int $position): float
    {
        if (isset($this->roles->zeroPrice[$position])) {
            return 0.0;
        }

        return isset($this->roles->overTwoDecimals[$position])
            ? $this->random->moneyOverTwoDecimals(199, 49_999)
            : $this->random->money(199, 49_999);
    }

    private function gtin(int $position): ?string
    {
        if (!isset($this->roles->gtin[$position])) {
            return null;
        }

        $gtin = SyntheticCodes::gtin13(self::GTIN_PREFIX, $position);

        return $position === $this->roles->whitespaceGtin ? ' ' . $gtin . ' ' : $gtin;
    }

    /**
     * @return list<int>
     */
    private function categoryIds(int $position): array
    {
        $categoryIds = $this->random->sample($this->categories->assignableIds, $this->roles->categoryCounts[$position] ?? 0);

        if ($this->roles->hasAnySale($position)) {
            $categoryIds[0] = $this->categories->saleCategoryId;
        }

        return $categoryIds;
    }

    /**
     * @return list<array{id: int, url: string, description: null, sort_order: int}>
     */
    private function images(int $externalId, int $count): array
    {
        $images = [];

        for ($index = 0; $index < $count; ++$index) {
            $images[] = [
                'id' => SeedIds::IMAGE_BASE + ($externalId - SeedIds::PRODUCT_BASE) * 32 + $index,
                'url' => \sprintf('https://images.seed.invalid/products/%d/%d.jpg', $externalId, $index),
                'description' => null,
                'sort_order' => $index,
            ];
        }

        return $images;
    }

    /**
     * @return array<string, mixed>
     */
    private function customFields(int $position): array
    {
        $fields = [
            'average_rating' => \number_format($this->random->int(30, 50) / 10, 1),
            'num_ratings' => (string) $this->random->int(0, 400),
        ];

        if (isset($this->roles->bestBuy[$position])) {
            $fields['best_buy'] = true;
        }

        if (isset($this->roles->dimensions[$position])) {
            $fields['dimensions'] = [$this->dimension(), $this->dimension(), $this->dimension()];
        }

        $freeDelivery = $this->roles->freeDelivery($position);

        return $freeDelivery === null ? $fields : [...$fields, 'free_delivery' => $freeDelivery];
    }

    private function dimension(): string
    {
        return $this->random->int(5, 120) . ' cm';
    }

    /**
     * Filters is a jsonb object keyed by filter slot, so an empty set must still encode as `{}`.
     *
     * @return array<int, list<string>>|stdClass
     */
    private function filters(int $position): array|stdClass
    {
        $filters = [];

        if ($this->roles->hasAnySale($position)) {
            $filters[self::ON_SALE_FILTER_SLOT] = ['On Sale'];
        }

        if (isset($this->roles->vatRelief[$position])) {
            $filters[self::VAT_RELIEF_FILTER_SLOT] = ['Yes'];
        }

        $freeDelivery = $this->roles->freeDelivery($position);

        if ($freeDelivery !== null) {
            $filters[self::SHIPPING_OFFERS_FILTER_SLOT] = ['Free ' . $freeDelivery . ' Delivery'];
        }

        return $filters === [] ? new stdClass() : $filters;
    }
}
