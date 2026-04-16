<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;

/**
 * Result wrapper for GetBrandUseCase.
 *
 * Carries the brand and the includes list so the presentation layer
 * knows which embeds were requested (controls serialization).
 */
final readonly class GetBrandResult
{
    /**
     * @param list<BrandInclude> $includes Requested embeds
     */
    public function __construct(
        public BrandView $brand,
        public array $includes,
    ) {}

    public function hasInclude(BrandInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
