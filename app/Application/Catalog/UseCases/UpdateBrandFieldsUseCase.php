<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandUpdateClientInterface;
use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\UnsupportedFieldException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Update scalar fields on a brand via ShopWired.
 *
 * Maps validated field names to BrandFieldUpdate VOs and delegates
 * to the field update client for a simple PUT.
 */
final readonly class UpdateBrandFieldsUseCase
{
    public function __construct(
        private BrandUpdateClientInterface $updateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $fields Validated field name => value map
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function execute(IntId $brandId, array $fields): void
    {
        $this->logger->info('Updating brand fields', [
            'brand_id' => $brandId->value,
            'field_names' => \array_keys($fields),
        ]);

        $this->updateClient->update($brandId->value, ...self::buildFieldUpdates($fields));

        $this->logger->info('Updated brand fields', [
            'brand_id' => $brandId->value,
        ]);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return list<BrandFieldUpdate>
     *
     * @throws UnsupportedFieldException When a field name is not supported
     */
    private static function buildFieldUpdates(array $fields): array
    {
        $updates = [];
        foreach ($fields as $name => $value) {
            $updates[] = match ($name) {
                'title' => BrandFieldUpdate::title($value),
                'description' => BrandFieldUpdate::description($value),
                'meta_title' => BrandFieldUpdate::metaTitle($value),
                'meta_description' => BrandFieldUpdate::metaDescription($value),
                default => throw new UnsupportedFieldException($name, 'brand'),
            };
        }

        return $updates;
    }
}
