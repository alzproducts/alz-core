<?php

declare(strict_types=1);

namespace App\Domain\ContactForm\ValueObjects;

use App\Domain\ContactForm\Enums\ProductSource;
use TypeError;
use ValueError;
use Webmozart\Assert\Assert;

/**
 * Product context when the form relates to a specific product.
 *
 * Only SKU is required - all other fields are optional for resilience
 * against frontend bugs or changes. Missing fields can be enriched
 * server-side by looking up the product if needed.
 *
 * Quantity is included here (not in ContactFormData) because it's
 * contextually tied to the product for quotation requests.
 */
final readonly class SelectedProduct
{
    public function __construct(
        public string $sku,
        public ?string $title = null,
        public ?string $price = null,
        public ?string $url = null,
        public ?ProductSource $source = null,
        public ?string $manualUrl = null,
        public ?int $quantity = null,
    ) {
        Assert::notEmpty($sku, 'Product SKU is required');

        if ($quantity !== null) {
            Assert::range($quantity, 1, 999, 'Quantity must be between 1 and 999');
        }
    }

    /**
     * Convert to array for JSONB storage.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'title' => $this->title,
            'price' => $this->price,
            'url' => $this->url,
            'source' => $this->source?->value,
            'manual_url' => $this->manualUrl,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * Create from JSONB array.
     *
     * @param array{sku: string, title?: string|null, price?: string|null, url?: string|null, source?: string|null, manual_url?: string|null, quantity?: int|null} $data
     *
     * @throws TypeError If sku is missing or not a string
     * @throws ValueError If source value is not a valid ProductSource
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            title: $data['title'] ?? null,
            price: $data['price'] ?? null,
            url: $data['url'] ?? null,
            source: isset($data['source']) ? ProductSource::from($data['source']) : null,
            manualUrl: $data['manual_url'] ?? null,
            quantity: $data['quantity'] ?? null,
        );
    }
}
