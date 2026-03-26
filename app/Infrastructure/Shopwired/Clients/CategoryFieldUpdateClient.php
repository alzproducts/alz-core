<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryFieldUpdateClientInterface;
use App\Domain\Catalog\Category\Enums\CategoryUpdatableField;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * Simple field updates for ShopWired categories.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class CategoryFieldUpdateClient implements CategoryFieldUpdateClientInterface
{
    private const string ENDPOINT = 'categories';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $categoryId, CategoryFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $categoryId, $payload);
    }

    private static function mapField(CategoryUpdatableField $field): string
    {
        return match ($field) {
            CategoryUpdatableField::Title => 'title',
            CategoryUpdatableField::Description => 'description',
            CategoryUpdatableField::MetaTitle => 'metaTitle',
            CategoryUpdatableField::MetaDescription => 'metaDescription',
        };
    }
}
