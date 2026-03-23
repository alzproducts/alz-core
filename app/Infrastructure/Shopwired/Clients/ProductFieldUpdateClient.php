<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Domain\Catalog\Product\Enums\ProductUpdatableField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * Simple field updates for ShopWired products.
 *
 * Maps domain field enums to ShopWired API field names via match expression.
 * PHPStan validates match exhaustiveness when new enum cases are added.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class ProductFieldUpdateClient implements ProductFieldUpdateClientInterface
{
    private const string ENDPOINT = 'products';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $productId, ProductFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $productId, $payload);
    }

    private static function mapField(ProductUpdatableField $field): string
    {
        return match ($field) {
            ProductUpdatableField::Title => 'title',
            ProductUpdatableField::Description => 'description',
            ProductUpdatableField::MetaTitle => 'metaTitle',
            ProductUpdatableField::MetaDescription => 'metaDescription',
            ProductUpdatableField::Categories => 'categories',
            ProductUpdatableField::SortOrder => 'sortOrder',
        };
    }
}
