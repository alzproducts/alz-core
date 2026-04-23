<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\CustomFieldMergerService;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
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
     * @throws RecordNotFoundException When product row not found in database
     */
    public function execute(int $productId, array $fieldNames = []): array
    {
        $this->logStart($productId, $fieldNames);

        $product = $this->productRepository->findDetailedProductView(
            IntId::from($productId),
            [ProductInclude::CustomFields],
        );

        $definitions = $this->customFieldRepository->findByItemType(CustomFieldItemType::Product);
        $fields = CustomFieldMergerService::mergeWithDefinitions($product->customFields, $definitions);
        $fields = self::filterByNames($fields, $fieldNames);

        $this->logEnd($productId, \count($fields));

        return $fields;
    }

    /**
     * @param list<string> $fieldNames
     */
    private function logStart(int $productId, array $fieldNames): void
    {
        $this->logger->info('Getting product custom fields', [
            'product_id' => $productId,
            'field_filter' => $fieldNames,
        ]);
    }

    private function logEnd(int $productId, int $fieldCount): void
    {
        $this->logger->info('Got product custom fields', [
            'product_id' => $productId,
            'field_count' => $fieldCount,
        ]);
    }

    /**
     * @param list<AbstractCustomFieldValue> $fields
     * @param list<string> $fieldNames
     *
     * @return list<AbstractCustomFieldValue>
     */
    private static function filterByNames(array $fields, array $fieldNames): array
    {
        if ($fieldNames === []) {
            return $fields;
        }

        return \array_values(\array_filter(
            $fields,
            static fn(AbstractCustomFieldValue $field): bool => \in_array($field->name(), $fieldNames, true),
        ));
    }
}
