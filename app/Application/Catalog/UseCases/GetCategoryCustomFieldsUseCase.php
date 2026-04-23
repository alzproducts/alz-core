<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\CustomFieldMergerService;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get enriched custom fields for a single category.
 *
 * Returns ALL defined custom fields with definition metadata (label, allowed_values, sort_order),
 * including fields with no value on the category (represented as NullCustomFieldValue).
 * Optionally filters to a subset of field names.
 */
final readonly class GetCategoryCustomFieldsUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CustomFieldRepositoryInterface $customFieldRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $fieldNames Optional filter — only return these field names
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws ResourceNotFoundException When no category matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function execute(int $categoryId, array $fieldNames = []): array
    {
        $this->logStart($categoryId, $fieldNames);

        $category = $this->categoryRepository->findCategoryForApi(
            IntId::from($categoryId),
            [CategoryInclude::CustomFields],
        );

        $definitions = $this->customFieldRepository->findByItemType(CustomFieldItemType::Category);
        $fields = CustomFieldMergerService::mergeWithDefinitions($category->customFields ?? [], $definitions);
        $fields = self::filterByNames($fields, $fieldNames);

        $this->logEnd($categoryId, \count($fields));

        return $fields;
    }

    /**
     * @param list<string> $fieldNames
     */
    private function logStart(int $categoryId, array $fieldNames): void
    {
        $this->logger->info('Getting category custom fields', [
            'category_id' => $categoryId,
            'field_filter' => $fieldNames,
        ]);
    }

    private function logEnd(int $categoryId, int $fieldCount): void
    {
        $this->logger->info('Got category custom fields', [
            'category_id' => $categoryId,
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
