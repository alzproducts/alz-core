<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use Webmozart\Assert\Assert;

/**
 * Product context when the form relates to a specific product.
 *
 * ProductId is required for reliable product identification. SKU is optional
 * as it may not always be available from the frontend. Other fields are
 * optional for resilience against frontend bugs or changes.
 *
 * Quantity is included here (not in ContactFormData) because it's
 * contextually tied to the product for quotation requests.
 */
final readonly class SelectedProduct
{
    public function __construct(
        public IntId $productId,
        public ?string $sku = null,
        public ?string $title = null,
        public ?Money $price = null,
        public ?string $url = null,
        public ?ProductSource $source = null,
        public ?string $manualUrl = null,
        public ?int $quantity = null,
    ) {
        if ($quantity !== null) {
            Assert::range($quantity, 1, 999, 'Quantity must be between 1 and 999');
        }
    }

    /**
     * Convert to array for JSONB storage.
     *
     * Price uses toNet(precision: null) to stay backward-compatible with existing rows. No migration needed.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId->value,
            'sku' => $this->sku,
            'title' => $this->title,
            'price' => $this->price !== null ? (string) $this->price->toNet(precision: null) : null,
            'url' => $this->url,
            'source' => $this->source?->value,
            'manual_url' => $this->manualUrl,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * Create from JSONB array.
     *
     * @param array{product_id: int, sku?: string|null, title?: string|null, price?: string|null, url?: string|null, source?: string|null, manual_url?: string|null, quantity?: int|null} $data
     *
     * @throws InvalidEnumValueException If source value is not a valid ProductSource
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: IntId::from($data['product_id']),
            sku: $data['sku'] ?? null,
            title: $data['title'] ?? null,
            price: isset($data['price']) ? Money::exclusiveFromString($data['price']) : null,
            url: $data['url'] ?? null,
            source: isset($data['source']) ? ProductSource::fromValue($data['source']) : null,
            manualUrl: $data['manual_url'] ?? null,
            quantity: $data['quantity'] ?? null,
        );
    }
}
