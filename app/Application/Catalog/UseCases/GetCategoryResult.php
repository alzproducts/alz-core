<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;

/**
 * Result wrapper for GetCategoryUseCase.
 *
 * Carries the category and the includes list so the presentation layer
 * knows which embeds were requested (controls serialization).
 */
final readonly class GetCategoryResult
{
    /**
     * @param list<CategoryInclude> $includes Requested embeds
     */
    public function __construct(
        public CategoryView $category,
        public array $includes,
    ) {}

    public function hasInclude(CategoryInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
