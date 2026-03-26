<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

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
     * @param list<string> $includes Requested embed names
     */
    public function __construct(
        public CategoryView $category,
        public array $includes,
    ) {}

    public function hasInclude(string $name): bool
    {
        return \in_array($name, $this->includes, true);
    }
}
