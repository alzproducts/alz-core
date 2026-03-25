<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get enriched custom fields for a single product.
 *
 * Returns ALL defined custom fields with definition metadata (label, allowed_values, sort_order),
 * including fields with no value on the product (represented as NullCustomFieldValue).
 * Optionally filters to a subset of field names.
 */
final readonly class GetProductCustomFieldsUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CustomFieldRepositoryInterface $customFieldRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $fieldNames Optional filter — only return these field names
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws ResourceNotFoundException When no product matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(int $productId, array $fieldNames = []): array
    {
        $this->logger->info('Getting product custom fields', [
            'product_id' => $productId,
            'field_filter' => $fieldNames,
        ]);

        $product = $this->productRepository->findProductForApi(
            IntId::from($productId),
            ['custom_fields'],
        );

        $definitions = $this->customFieldRepository->findByItemType(CustomFieldItemType::Product);
        $fields = self::mergeWithDefinitions($product->customFields, $definitions);

        // Apply field name filter after merge
        if ($fieldNames !== []) {
            $fields = \array_values(\array_filter(
                $fields,
                static fn(AbstractCustomFieldValue $field): bool => \in_array($field->name(), $fieldNames, true),
            ));
        }

        $this->logger->info('Got product custom fields', [
            'product_id' => $productId,
            'field_count' => \count($fields),
        ]);

        return $fields;
    }

    /**
     * Merge populated custom fields with all definitions, filling gaps with NullCustomFieldValue.
     *
     * Ensures every defined field is represented in the result. Populated fields not in
     * definitions are appended for forward compatibility. Result is sorted by sortOrder (null last).
     *
     * @param list<AbstractCustomFieldValue> $populatedFields Fields with values from the product
     * @param list<CustomFieldDefinition> $definitions All product custom field definitions
     *
     * @return list<AbstractCustomFieldValue>
     */
    private static function mergeWithDefinitions(array $populatedFields, array $definitions): array
    {
        // Index populated fields by name for O(1) lookup
        $populatedByName = [];
        foreach ($populatedFields as $field) {
            $populatedByName[$field->name()] = $field;
        }

        // Build merged list: use populated value or create NullCustomFieldValue
        $fields = [];
        foreach ($definitions as $definition) {
            $fields[] = $populatedByName[$definition->name]
                ?? new NullCustomFieldValue($definition);
        }

        // Append populated fields not in definitions (forward compatibility)
        foreach ($populatedByName as $name => $field) {
            if (\array_find($fields, static fn(AbstractCustomFieldValue $f): bool => $f->name() === $name) === null) {
                $fields[] = $field;
            }
        }

        // Sort by sortOrder (null last)
        \usort($fields, static function (AbstractCustomFieldValue $a, AbstractCustomFieldValue $b): int {
            $aSort = $a->definition->sortOrder;
            $bSort = $b->definition->sortOrder;

            if ($aSort === null && $bSort === null) {
                return 0;
            }
            if ($aSort === null) {
                return 1;
            }
            if ($bSort === null) {
                return -1;
            }

            return $aSort <=> $bSort;
        });

        return $fields;
    }
}
