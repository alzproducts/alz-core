<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

final readonly class CategoryTree
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $assignableIds every category external ID except the sale category
     */
    public function __construct(
        public array $rows,
        public array $assignableIds,
        public int $saleCategoryId,
    ) {}
}
