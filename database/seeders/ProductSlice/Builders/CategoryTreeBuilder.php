<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\CategoryRowFactory;
use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SeedProfile;

/**
 * Builds the category forest level by level from the profile's depth histogram. Parents always come from
 * the level above, so the products view's recursive ancestor walk (UNION ALL, no cycle guard) terminates.
 * Categories with two parents hang off two roots: each yields three ancestor rows, the same as a depth-2
 * category, which keeps the ancestor CTE at the profile's row count.
 */
final readonly class CategoryTreeBuilder
{
    public const string SALE_CATEGORY_TITLE = 'Sale';

    public function __construct(
        private SeedRandom $random,
    ) {}

    public function build(SeedProfile $profile, int $saleCategoryId): CategoryTree
    {
        $parents = $this->parentsByCategory($profile);
        $ids = \array_keys($parents);
        $roots = \array_keys(\array_filter($parents, static fn(array $parentIds): bool => $parentIds === []));
        $main = $this->random->subset($roots, $profile->int('counts.categories_main'));
        $headedCount = $profile->int('counts.categories') - $profile->int('categories.custom_fields_not_object') - \count($main);
        $headed = $this->random->subset(SeedRandom::without($ids, $main), $headedCount);
        $active = $this->random->subset($ids, $profile->int('counts.categories_active') - 1);

        $factory = CategoryRowFactory::new();
        $rows = [$this->row($factory, ['external_id' => $saleCategoryId, 'title' => self::SALE_CATEGORY_TITLE])];

        foreach ($parents as $id => $parentIds) {
            $rows[] = $this->row($factory, [
                'external_id' => $id,
                'parent_ids' => $parentIds,
                'active' => isset($active[$id]),
                'custom_fields' => self::customFields(isset($main[$id]), isset($headed[$id])),
            ]);
        }

        return new CategoryTree($rows, $ids, $saleCategoryId);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(CategoryRowFactory $factory, array $overrides): array
    {
        $createdAt = $this->random->timestamp();

        return $factory->row([
            ...$overrides,
            'shopwired_created_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * Every non-sale category's parent IDs, keyed by external ID, parents before children.
     *
     * @return array<int, list<int>>
     */
    private function parentsByCategory(SeedProfile $profile): array
    {
        $doubleParentCount = $profile->histogram('categories.parent_ids_length')['2'] ?? 0;
        $nextId = SeedIds::CATEGORY_BASE;
        $levels = [];
        $parents = [];

        foreach ($profile->intHistogram('categories.depth') as $depth => $count) {
            // intentional: the sale category is one of the profile's roots
            $levelIds = self::idRange($nextId, $depth === 0 ? $count - 1 : $count);
            $nextId += \count($levelIds);

            foreach ($levelIds as $position => $id) {
                $parents[$id] = $this->parentsFor($depth, $position < $doubleParentCount, $levels);
            }

            $levels[$depth] = $levelIds;
        }

        return $parents;
    }

    /**
     * @param array<int, list<int>> $levels
     *
     * @return list<int>
     */
    private function parentsFor(int $depth, bool $doubleParentSlot, array $levels): array
    {
        if ($depth === 0) {
            return [];
        }

        if ($depth === 2 && $doubleParentSlot) {
            return $this->random->sample($levels[0] ?? [], 2);
        }

        return [$this->random->pick($levels[$depth - 1] ?? [])];
    }

    /**
     * @return list<int>
     */
    private static function idRange(int $first, int $size): array
    {
        return $size > 0 ? \range($first, $first + $size - 1) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customFields(bool $isMain, bool $isHeaded): array
    {
        if ($isMain) {
            return ['is_main_category' => true];
        }

        return $isHeaded ? ['custom_h1' => 'Browse the full range'] : [];
    }
}
