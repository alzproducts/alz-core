<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use BackedEnum;
use JsonException;

/**
 * AC11 for the seed profile JSON. Every key path must match {@see self::shape()}, a reviewed allowlist of counts,
 * ratios and distributions, and every fixed key in it must be present. Leaves are numbers, apart from the export
 * timestamp and the free-delivery type names. A key path naming a price, cost, margin, title, SKU, supplier, email
 * or name may only hold an integer count, or a ratio of counts the allowlist declares as one: `products.price_zero`
 * is a count of rows, `products.avg_price` would be a figure.
 */
final class ProfileJsonCheck
{
    public const array BANNED_WORDS = ['price', 'cost', 'margin', 'title', 'sku', 'supplier', 'email', 'name'];

    private const string INT = 'int';

    /** An average of row counts, e.g. SKU snapshot rows per date. */
    private const string COUNT_RATIO = 'count_ratio';

    private const string TIMESTAMP = 'timestamp';

    private const string DELIVERY_TYPE = 'delivery_type';

    /** A map shape holding this key may also be JSON null (`jsonb_object_agg` over no rows). */
    private const string NULLABLE = '?';

    private const array LEAF_PATTERNS = [
        self::TIMESTAMP => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/',
        self::DELIVERY_TYPE => '/^(Standard|Express)$/',
    ];

    /**
     * @return list<string>
     */
    public static function violations(string $json): array
    {
        try {
            $profile = \json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['profile is not valid JSON: ' . $exception->getMessage()];
        }

        if (!\is_array($profile) || $profile === []) {
            return ['profile is not a non-empty JSON object'];
        }

        return self::walk($profile, self::shape(), '');
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, mixed> $shape
     *
     * @return list<string>
     */
    private static function walk(array $node, array $shape, string $path): array
    {
        $violations = [];

        foreach ($shape as $key => $childShape) {
            if ($key !== self::NULLABLE && !self::isPlaceholder($key) && !\array_key_exists($key, $node)) {
                $violations[] = 'missing key path ' . self::join($path, $key);
            }
        }

        foreach ($node as $key => $value) {
            $childPath = self::join($path, (string) $key);
            $childShape = self::childShape($shape, (string) $key);

            if ($childShape === null) {
                $violations[] = 'key path not in the allowlist: ' . $childPath;

                continue;
            }

            \array_push($violations, ...self::check($value, $childShape, $childPath));
        }

        return $violations;
    }

    /**
     * @param array<string, mixed>|string $shape
     *
     * @return list<string>
     */
    private static function check(mixed $value, array|string $shape, string $path): array
    {
        if (!\is_array($value) && $shape !== self::COUNT_RATIO && self::containsBannedWord($path) && !\is_int($value)) {
            return ['key path naming a banned word holds something other than a count: ' . $path];
        }

        if (\is_array($shape)) {
            if ($value === null && \array_key_exists(self::NULLABLE, $shape)) {
                return [];
            }

            return \is_array($value) ? self::walk($value, $shape, $path) : ['expected an object or list at ' . $path];
        }

        return self::leafMatches($value, $shape) ? [] : [\sprintf('expected %s at %s', $shape, $path)];
    }

    private static function leafMatches(mixed $value, string $kind): bool
    {
        return match ($kind) {
            self::INT => \is_int($value),
            self::COUNT_RATIO => \is_int($value) || \is_float($value),
            default => \is_string($value) && \preg_match(self::LEAF_PATTERNS[$kind], $value) === 1,
        };
    }

    /**
     * @param array<string, mixed> $shape
     *
     * @return array<string, mixed>|string|null
     */
    private static function childShape(array $shape, string $key): array|string|null
    {
        if ($key !== self::NULLABLE && \array_key_exists($key, $shape) && !self::isPlaceholder($key)) {
            /** @var array<string, mixed>|string */
            return $shape[$key];
        }

        foreach ($shape as $pattern => $childShape) {
            if (self::isPlaceholder($pattern) && \preg_match(self::placeholderPattern($pattern), $key) === 1) {
                /** @var array<string, mixed>|string */
                return $childShape;
            }
        }

        return null;
    }

    private static function isPlaceholder(string $key): bool
    {
        return \str_starts_with($key, '{') && \str_ends_with($key, '}');
    }

    private static function placeholderPattern(string $placeholder): string
    {
        $values = static fn(array $cases): string => \implode('|', \array_map(static fn(BackedEnum $case): string => \preg_quote((string) $case->value, '/'), $cases));

        return match ($placeholder) {
            '{n}' => '/^\d+$/',
            '{bucket}' => '/^(\d+|\d+-\d+|\d+\+)$/',
            '{field}' => '/^[a-z][a-z0-9_]*$/',
            '{json_type}' => '/^(string|number|boolean|array|object|null|<null>)$/',
            '{definition_slot}' => '/^(' . $values(CustomFieldItemType::cases()) . ')\/(' . $values(CustomFieldType::cases()) . ')$/',
            '{free_delivery_value}' => '/^(|<null>|Standard|Express)$/',
            '{credit_tier}' => '/^(<null>|Credit - Tier \d+)$/',
            '{currency}' => '/^(<null>|[A-Z]{3})$/',
        };
    }

    private static function containsBannedWord(string $path): bool
    {
        $lower = \mb_strtolower($path);

        return \array_any(self::BANNED_WORDS, static fn(string $word): bool => \str_contains($lower, $word));
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape(): array
    {
        $histogram = ['{n}' => self::INT];
        $buckets = ['{bucket}' => self::INT];

        return [
            'exported_at' => self::TIMESTAMP,
            'counts' => self::ints([
                'categories', 'categories_active', 'categories_main', 'cost_price_changes', 'custom_field_definitions',
                'filter_groups', 'free_delivery_shipping_costs', 'price_periods', 'price_periods_current',
                'product_extra_data', 'product_sale_settings', 'products', 'products_active', 'products_view',
                'sku_aliases', 'stock_item_suppliers', 'stock_items', 'suppliers', 'variations',
            ]),
            'products' => [
                ...self::ints([
                    'compare_price_positive', 'cost_price_null', 'cost_price_zero', 'duplicate_title_groups',
                    'gtin_null_or_empty', 'gtin_untrimmed', 'in_any_main_category_tree', 'max_duplicate_title_group',
                    'price_null', 'price_over_2dp', 'price_zero', 'products_in_duplicate_titles',
                    'sale_price_not_below_price', 'sale_price_over_2dp', 'sale_price_positive', 'sale_price_zero',
                    'sku_null', 'sku_untrimmed', 'sku_with_stock_item', 'vat_exclusive', 'vat_relief',
                    'view_has_any_sale', 'view_is_on_sale',
                ]),
                'variations_per_product' => $histogram,
                'categories_per_product' => $histogram,
                'images_per_product' => $histogram,
                'custom_field_keys' => ['{field}' => ['{json_type}' => self::INT]],
                'custom_fields_not_object' => [self::NULLABLE => true, '{json_type}' => self::INT],
                'free_delivery_values' => ['{free_delivery_value}' => self::INT],
                'filter_slots' => ['{n}' => [
                    'products' => self::INT,
                    'max_values' => self::INT,
                    'avg_values' => self::COUNT_RATIO,
                    'distinct_values' => self::INT,
                ]],
            ],
            'variations' => [
                ...self::ints([
                    'cost_price_null', 'cost_price_zero', 'gtin_null_or_empty', 'gtin_untrimmed', 'price_null',
                    'price_over_2dp', 'price_zero', 'sale_price_null', 'sale_price_positive', 'sale_price_zero',
                    'sku_null', 'sku_untrimmed', 'view_is_on_sale', 'with_stock_item', 'without_stock_item',
                ]),
                'options_per_variation' => $histogram,
                'distinct_options_per_product' => $histogram,
            ],
            'stock_items' => [
                ...self::ints([
                    'is_archived', 'is_composite', 'is_logically_deleted', 'item_number_duplicated',
                    'item_number_null_or_empty', 'item_number_untrimmed', 'jit', 'matches_no_sku',
                    'matches_product_sku', 'matches_variation_sku', 'purchase_price_null', 'purchase_price_zero',
                ]),
                'suppliers_per_stock_item' => $histogram,
            ],
            'stock_item_suppliers' => [
                ...self::ints([
                    'default_with_purchase_price', 'distinct_supplier_ids', 'is_default', 'links_without_stock_item',
                    'purchase_price_null', 'purchase_price_over_2dp', 'purchase_price_zero',
                    'stock_items_with_multiple_defaults', 'supplier_ids_not_in_suppliers',
                ]),
                'links_per_supplier' => $buckets,
                'supplier_currency' => ['{currency}' => self::INT],
            ],
            'categories' => [
                ...self::ints(['ancestor_rows', 'cat_main_map_rows', 'custom_fields_not_object']),
                'parent_ids_length' => $histogram,
                'depth' => $histogram,
                'children_per_parent' => $histogram,
                'products_per_category' => $buckets,
                'custom_field_keys' => ['{field}' => self::INT],
            ],
            'custom_field_definitions' => ['{definition_slot}' => self::INT],
            'filter_group_option_nos' => ['{n}' => self::INT],
            'free_delivery_types' => ['{n}' => self::DELIVERY_TYPE],
            'product_extra_data' => self::ints(['rrp_null', 'sku_matches_catalog']),
            'price_periods' => [
                ...self::ints(['current_sku_matches_catalog', 'distinct_skus', 'price_has_tax']),
                'periods_per_sku' => $buckets,
            ],
            'cost_price_changes' => [
                ...self::ints(['distinct_skus', 'distinct_supplier_ids']),
                'changes_per_sku' => $buckets,
            ],
            'popularity' => [
                ...self::ints([
                    'credit_latest_rows', 'product_latest_rows', 'sku_latest_matches_catalog', 'sku_latest_rows',
                    'sku_snapshot_dates', 'sku_snapshot_rows',
                ]),
                'credit_tiers' => ['{credit_tier}' => self::INT],
                'sku_rows_per_date' => ['min' => self::INT, 'max' => self::INT, 'avg' => self::COUNT_RATIO],
            ],
        ];
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function ints(array $keys): array
    {
        return \array_fill_keys($keys, self::INT);
    }
}
