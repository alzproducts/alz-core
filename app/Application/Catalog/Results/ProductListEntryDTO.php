<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

use App\Domain\Catalog\Product\ValueObjects\ProductView;
use Webmozart\Assert\Assert;

/**
 * One product of a list page: either its stored fragment body, still valid for the
 * fingerprint, or the view to render because no valid fragment exists.
 */
final readonly class ProductListEntryDTO
{
    public function __construct(
        public string $productId,
        public string $fingerprint,
        public ?string $cachedBody,
        public ?ProductView $view,
    ) {
        Assert::true(
            ($cachedBody === null) !== ($view === null),
            'A product list entry carries exactly one of a cached body and a view',
        );
    }
}
