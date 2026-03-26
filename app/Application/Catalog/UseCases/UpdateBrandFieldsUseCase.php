<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandFieldUpdateClientInterface;
use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use LogicException;
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
        private BrandFieldUpdateClientInterface $fieldUpdateClient,
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

        $updates = [];
        foreach ($fields as $name => $value) {
            $updates[] = match ($name) {
                'title' => BrandFieldUpdate::title($value),
                'description' => BrandFieldUpdate::description($value),
                'meta_title' => BrandFieldUpdate::metaTitle($value),
                'meta_description' => BrandFieldUpdate::metaDescription($value),
                default => throw new LogicException("Unknown brand field: {$name}"),
            };
        }

        $this->fieldUpdateClient->update($brandId->value, ...$updates);

        $this->logger->info('Updated brand fields', [
            'brand_id' => $brandId->value,
        ]);
    }
}
