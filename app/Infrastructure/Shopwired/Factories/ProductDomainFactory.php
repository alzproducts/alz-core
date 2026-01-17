<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductListCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ValueListCustomFieldValue;
use App\Domain\Catalog\Product\Exceptions\MissingVariationSkuException;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidGtinException;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;
use App\Infrastructure\Shopwired\Responses\ProductImageResponse;
use App\Infrastructure\Shopwired\Responses\ProductResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationOptionResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationResponse;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating Product domain objects from API responses.
 *
 * Handles the transformation of raw custom field values into typed CustomFieldValue
 * objects by looking up field definitions in the registry.
 *
 * **Lifecycle**: Register with `scoped()` binding to ensure fresh instance per queue job.
 * This prevents stale custom field definitions in Octane long-running processes.
 *
 * @see Product
 */
final class ProductDomainFactory
{
    private ?CustomFieldDefinitionRegistry $registry = null;

    public function __construct(
        private readonly CustomFieldRepositoryInterface $customFieldRepository,
    ) {}

    /**
     * Create a Product domain object from an API response.
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function fromResponse(ProductResponse $response): Product
    {
        return new Product(
            id: $response->id,
            sku: $response->sku,
            gtin: $this->buildGtin($response->gtin, $response->id),
            title: $response->title,
            description: $response->description,
            slug: $response->slug,
            url: $response->url,
            price: $response->price,
            costPrice: $response->costPrice,
            salePrice: $response->salePrice,
            comparePrice: $response->comparePrice,
            stock: $response->stock,
            isActive: $response->isActive,
            vatExclusive: $response->vatExclusive,
            vatRelief: $response->vatRelief,
            weight: $response->weight,
            metaTitle: $response->metaTitle,
            metaDescription: $response->metaDescription,
            categoryIds: $response->categoryIds,
            variations: $this->buildVariations($response->id, $response->variations),
            images: $this->buildImages($response->images),
            customFields: $this->buildCustomFields($response->customFields),
            createdAt: $response->createdAt->toDateTimeImmutable(),
            updatedAt: $response->updatedAt->toDateTimeImmutable(),
        );
    }

    /**
     * Build variations, logging and skipping any with missing SKUs.
     *
     * @param list<ProductVariationResponse> $variations
     *
     * @return list<ProductVariation>
     */
    private function buildVariations(int $productExternalId, array $variations): array
    {
        $result = [];

        foreach ($variations as $variation) {
            try {
                $result[] = $variation->toDomain($productExternalId);
            } catch (MissingVariationSkuException $e) {
                Log::error('Skipping product variation with missing SKU - fix in ShopWired admin', [
                    'variation_id' => $e->variationId,
                    'product_external_id' => $e->productExternalId,
                    'options' => $this->buildOptionsDisplayString($variation),
                ]);
            }
        }

        return $result;
    }

    /**
     * Build a display string from variation options (e.g., "Size: Large, Color: Red").
     */
    private function buildOptionsDisplayString(ProductVariationResponse $variation): string
    {
        if ($variation->options === []) {
            return '(no options)';
        }

        return \implode(', ', \array_map(
            static fn(ProductVariationOptionResponse $opt): string => "{$opt->optionName}: {$opt->valueName}",
            $variation->options,
        ));
    }

    /**
     * @param list<ProductImageResponse> $images
     *
     * @return list<ProductImage>
     */
    private function buildImages(array $images): array
    {
        return \array_map(
            static fn(ProductImageResponse $img): ProductImage => $img->toDomain(),
            $images,
        );
    }

    /**
     * Build a GTIN value object from a raw string, logging invalid values.
     *
     * Invalid GTINs are logged and treated as null (product continues sync).
     */
    private function buildGtin(?string $gtin, int $productExternalId): ?Gtin
    {
        if ($gtin === null || $gtin === '') {
            return null;
        }

        try {
            return Gtin::fromString($gtin);
        } catch (InvalidGtinException $e) {
            Log::warning('Invalid GTIN in product - fix in ShopWired admin', [
                'product_external_id' => $productExternalId,
                'gtin' => $gtin,
                'reason' => $e->reason,
            ]);

            return null;
        }
    }

    /**
     * Build typed custom field values from raw API data.
     *
     * Unknown field names are logged as warnings and skipped (may indicate
     * custom field definitions are out of sync - re-run SyncCustomFieldsJob).
     * Type mismatches throw InvalidCustomFieldValueException.
     *
     * @param array<string, mixed> $rawFields
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function buildCustomFields(array $rawFields): array
    {
        $result = [];

        foreach ($rawFields as $name => $value) {
            $definition = $this->registry()->findByName($name);

            if ($definition === null) {
                // Field not in registry - likely a new field added in ShopWired
                Log::warning('Unknown custom field in product - re-run SyncCustomFieldsJob', [
                    'field_name' => $name,
                    'item_type' => CustomFieldItemType::Product->value,
                ]);

                continue;
            }

            $result[] = $this->createTypedValue($definition, $value);
        }

        return $result;
    }

    /**
     * Create a typed CustomFieldValue from a definition and raw value.
     *
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     */
    private function createTypedValue(CustomFieldDefinition $definition, mixed $value): AbstractCustomFieldValue
    {
        return match ($definition->type) {
            CustomFieldType::Text,
            CustomFieldType::Choice,
            CustomFieldType::List => $this->createStringValue($definition, $value),

            CustomFieldType::Toggle => $this->createToggleValue($definition, $value),

            CustomFieldType::Date,
            CustomFieldType::DateTime => $this->createDateTimeValue($definition, $value),

            CustomFieldType::ValueList => $this->createValueListValue($definition, $value),

            CustomFieldType::ProductList => $this->createProductListValue($definition, $value),
        };
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private function createStringValue(CustomFieldDefinition $definition, mixed $value): StringCustomFieldValue
    {
        if (!\is_string($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new StringCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private function createToggleValue(CustomFieldDefinition $definition, mixed $value): ToggleCustomFieldValue
    {
        if (!\is_bool($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return new ToggleCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private function createDateTimeValue(CustomFieldDefinition $definition, mixed $value): DateTimeCustomFieldValue
    {
        if (!\is_int($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        return DateTimeCustomFieldValue::fromTimestamp($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private function createValueListValue(CustomFieldDefinition $definition, mixed $value): ValueListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        // Validate all items are strings
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->name,
                    expectedType: $definition->type,
                    actualType: 'array with non-string element: ' . \get_debug_type($item),
                    rawValue: $value,
                );
            }
        }

        /** @var list<string> $value */
        return new ValueListCustomFieldValue($definition, $value);
    }

    /**
     * @throws InvalidCustomFieldValueException
     */
    private function createProductListValue(CustomFieldDefinition $definition, mixed $value): ProductListCustomFieldValue
    {
        if (!\is_array($value)) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->name,
                expectedType: $definition->type,
                actualType: \get_debug_type($value),
                rawValue: $value,
            );
        }

        // Validate all items are positive integers
        foreach ($value as $item) {
            if (!\is_int($item) || $item <= 0) {
                throw new InvalidCustomFieldValueException(
                    fieldName: $definition->name,
                    expectedType: $definition->type,
                    actualType: 'array with invalid product ID: ' . \get_debug_type($item),
                    rawValue: $value,
                );
            }
        }

        /** @var list<int> $value */
        return new ProductListCustomFieldValue($definition, $value);
    }

    /**
     * Get the custom field definition registry, lazy-loading on first access.
     *
     * @throws DatabaseOperationFailedException When query fails
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function registry(): CustomFieldDefinitionRegistry
    {
        if ($this->registry === null) {
            $definitions = $this->customFieldRepository->findAll();
            $this->registry = CustomFieldDefinitionRegistry::forItemType($definitions, CustomFieldItemType::Product);
        }

        return $this->registry;
    }
}
