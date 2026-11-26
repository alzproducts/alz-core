<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

use InvalidArgumentException;

/**
 * The seed's reserved int4 ID range: below the integration guard tests' fixture IDs (2147483001 and up),
 * so a seeded ID never collides with a guard fixture or names a real remote record.
 */
final class SeedIds
{
    public const int RANGE_MIN = 2_000_000_000;

    public const int RANGE_MAX = 2_147_483_000;

    public const int SINGLETON_SLOT_MIN = 2_000_000_000;

    public const int SINGLETON_SLOT_MAX = 2_000_000_099;

    public const int CATEGORY_BASE = 2_000_000_100;

    public const int PRODUCT_BASE = 2_010_000_000;

    public const int VARIATION_BASE = 2_020_000_000;

    public const int CUSTOM_FIELD_BASE = 2_030_000_000;

    public const int FILTER_GROUP_BASE = 2_040_000_000;

    public const int IMAGE_BASE = 2_050_000_000;

    public const int OPTION_BASE = 2_060_000_000;

    public const int OPTION_VALUE_BASE = 2_070_000_000;

    public const int ORDER_BASE = 2_080_000_000;

    /**
     * Order references sit outside the reserved range, as 4-digit integers: the alz-admin QuickBooks sync page
     * accepts at most 8 characters, so a 10-digit seeded reference could not be typed into it. A real reference
     * starts with A and is longer than 4 digits, so a seeded one never matches a real one. A reference is the base
     * plus the order's slot, so the bounds hold while the slots stay below ORDER_REFERENCE_MAX - ORDER_REFERENCE_BASE.
     */
    public const int ORDER_REFERENCE_BASE = 1_000;

    public const int ORDER_REFERENCE_MAX = 9_999;

    public const int ORDER_LINE_BASE = 2_100_000_000;

    public const int ORDER_REFUND_BASE = 2_110_000_000;

    public const int CUSTOMER_BASE = 2_120_000_000;

    public const int ORDER_STATUS_BASE = 2_130_000_000;

    /**
     * Filter groups whose external IDs the code and guard tests pin: external_id => option_no.
     * These are the only seeded external IDs outside the reserved range.
     */
    public const array PINNED_FILTER_GROUPS = [
        10073 => 14,
        11411 => 20,
        11412 => 25,
        240 => 2,
    ];

    public static function assertSaleCategorySlot(int $saleCategoryId): void
    {
        if ($saleCategoryId < self::SINGLETON_SLOT_MIN || $saleCategoryId > self::SINGLETON_SLOT_MAX) {
            throw new InvalidArgumentException('Sale category ID must sit in the seed\'s reserved singleton slots 2000000000-2000000099');
        }
    }

    public static function inReservedRange(int $id): bool
    {
        return $id >= self::RANGE_MIN && $id <= self::RANGE_MAX;
    }
}
