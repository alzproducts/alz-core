<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

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
     * @param list<string> $includes Requested embed names
     */
    public function __construct(
        public BrandView $brand,
        public array $includes,
    ) {}

    public function hasInclude(string $name): bool
    {
        return \in_array($name, $this->includes, true);
    }
}
