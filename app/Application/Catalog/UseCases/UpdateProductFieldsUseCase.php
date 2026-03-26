<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use LogicException;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Update scalar fields on a product via ShopWired.
 *
 * Maps validated field names to ProductFieldUpdate VOs and delegates
 * to the field update client for a simple PUT.
 */
final readonly class UpdateProductFieldsUseCase
{
    public function __construct(
        private ProductFieldUpdateClientInterface $fieldUpdateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $fields Validated field name => value map
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function execute(IntId $productId, array $fields): void
    {
        $this->logger->info('Updating product fields', [
            'product_id' => $productId->value,
            'field_names' => \array_keys($fields),
        ]);

        $updates = self::mapFieldUpdates($fields);
        $this->fieldUpdateClient->update($productId->value, ...$updates);

        $this->logger->info('Updated product fields', [
            'product_id' => $productId->value,
        ]);
    }

    /**
     * Map validated field names to typed ProductFieldUpdate VOs.
     *
     * @param array<string, mixed> $fields Validated field name => value map
     *
     * @return list<ProductFieldUpdate>
     */
    private static function mapFieldUpdates(array $fields): array
    {
        $updates = [];

        foreach ($fields as $name => $value) {
            $updates[] = match ($name) {
                'title',
                'description',
                'meta_title',
                'meta_description' => self::mapStringField($name, $value),
                'categories' => self::mapCategoriesField($value),
                'sort_order' => self::mapSortOrderField($value),
                default => throw new LogicException("Unknown product field: {$name}"),
            };
        }

        return $updates;
    }

    private static function mapStringField(string $name, mixed $value): ProductFieldUpdate
    {
        Assert::string($value);

        return match ($name) {
            'title' => ProductFieldUpdate::title($value),
            'description' => ProductFieldUpdate::description($value),
            'meta_title' => ProductFieldUpdate::metaTitle($value),
            'meta_description' => ProductFieldUpdate::metaDescription($value),
            default => throw new LogicException("Unknown string field: {$name}"),
        };
    }

    private static function mapCategoriesField(mixed $value): ProductFieldUpdate
    {
        Assert::isArray($value);

        /** @var list<int> $value */
        return ProductFieldUpdate::categories($value);
    }

    private static function mapSortOrderField(mixed $value): ProductFieldUpdate
    {
        Assert::integer($value);

        return ProductFieldUpdate::sortOrder($value);
    }
}
