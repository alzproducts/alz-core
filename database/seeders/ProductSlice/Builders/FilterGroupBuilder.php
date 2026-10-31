<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\FilterGroupRowFactory;
use Database\Seeders\ProductSlice\SeedIds;

/**
 * Only the filter groups the code and the integration guard tests pin.
 */
final readonly class FilterGroupBuilder
{
    public const int CUSTOMER_RATING_OPTION_NO = 15;

    public const string CUSTOMER_RATING_TITLE = 'Customer Rating';

    /** option_no => title */
    private const array TITLES = [
        14 => 'Offers',
        20 => 'Shipping Offers',
        25 => 'Shipping Options',
        2 => 'VAT Relief',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function build(): array
    {
        $factory = FilterGroupRowFactory::new();
        $rows = [];

        foreach (SeedIds::PINNED_FILTER_GROUPS as $externalId => $optionNo) {
            $rows[] = $factory->row([
                'external_id' => $externalId,
                'title' => self::TITLES[$optionNo],
                'option_no' => $optionNo,
                'sort_order' => \count($rows),
            ]);
        }

        $rows[] = $factory->row([
            'external_id' => SeedIds::FILTER_GROUP_BASE,
            'title' => self::CUSTOMER_RATING_TITLE,
            'option_no' => self::CUSTOMER_RATING_OPTION_NO,
            'sort_order' => \count($rows),
        ]);

        return $rows;
    }
}
