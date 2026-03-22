<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\BrandFieldUpdateClientInterface;
use App\Domain\Catalog\Brand\Enums\BrandUpdatableField;
use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * Simple field updates for ShopWired brands.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class BrandFieldUpdateClient implements BrandFieldUpdateClientInterface
{
    private const string ENDPOINT = 'brands';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $brandId, BrandFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $brandId, $payload);
    }

    private static function mapField(BrandUpdatableField $field): string
    {
        return match ($field) {
            BrandUpdatableField::Title => 'title',
        };
    }
}
