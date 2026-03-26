<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\CategoryFieldUpdateClientInterface;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Update scalar fields on a category via ShopWired.
 *
 * Maps validated field names to CategoryFieldUpdate VOs and delegates
 * to the field update client for a simple PUT.
 */
final readonly class UpdateCategoryFieldsUseCase
{
    public function __construct(
        private CategoryFieldUpdateClientInterface $fieldUpdateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $fields Validated field name => value map
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function execute(IntId $categoryId, array $fields): void
    {
        $this->logger->info('Updating category fields', [
            'category_id' => $categoryId->value,
            'field_names' => \array_keys($fields),
        ]);

        $updates = [];
        foreach ($fields as $name => $value) {
            $updates[] = match ($name) {
                'title' => CategoryFieldUpdate::title($value),
                'description' => CategoryFieldUpdate::description($value),
                'meta_title' => CategoryFieldUpdate::metaTitle($value),
                'meta_description' => CategoryFieldUpdate::metaDescription($value),
                default => throw new LogicException("Unknown category field: {$name}"),
            };
        }

        $this->fieldUpdateClient->update($categoryId->value, ...$updates);

        $this->logger->info('Updated category fields', [
            'category_id' => $categoryId->value,
        ]);
    }
}
