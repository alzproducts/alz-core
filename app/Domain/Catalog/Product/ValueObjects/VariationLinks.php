<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

final readonly class VariationLinks
{
    public string $publicUrl;

    /**
     * @param string $parentPublicUrl Parent product's public URL
     * @param string $editWebsiteUrl Parent product's edit URL (variations edit on the product page)
     * @param Sku|null $sku Variation SKU — appended as ?var= query param when present
     */
    public function __construct(
        string $parentPublicUrl,
        public string $editWebsiteUrl,
        ?Sku $sku,
    ) {
        $this->publicUrl = $sku !== null
            ? $parentPublicUrl . '?var=' . \urlencode($sku->value)
            : $parentPublicUrl;
    }
}
