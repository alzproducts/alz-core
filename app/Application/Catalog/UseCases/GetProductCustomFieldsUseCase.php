<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get enriched custom fields for a single product.
 *
 * Returns all custom fields with definition metadata (label, allowed_values, sort_order).
 * Optionally filters to a subset of field names.
 */
final readonly class GetProductCustomFieldsUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
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

        $fields = $product->customFields;

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
}
