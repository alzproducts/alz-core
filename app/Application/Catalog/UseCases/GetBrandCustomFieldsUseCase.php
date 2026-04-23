<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\CustomFieldMergerService;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
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
 * Get enriched custom fields for a single brand.
 *
 * Returns ALL defined custom fields with definition metadata (label, allowed_values, sort_order),
 * including fields with no value on the brand (represented as NullCustomFieldValue).
 * Optionally filters to a subset of field names.
 */
final readonly class GetBrandCustomFieldsUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
        private CustomFieldRepositoryInterface $customFieldRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $fieldNames Optional filter — only return these field names
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws ResourceNotFoundException When no brand matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function execute(int $brandId, array $fieldNames = []): array
    {
        $this->logStart($brandId, $fieldNames);

        $brand = $this->brandRepository->findBrandForApi(
            IntId::from($brandId),
            [BrandInclude::CustomFields],
        );

        $definitions = $this->customFieldRepository->findByItemType(CustomFieldItemType::Brand);
        $fields = CustomFieldMergerService::mergeWithDefinitions($brand->customFields ?? [], $definitions);
        $fields = self::filterByNames($fields, $fieldNames);

        $this->logEnd($brandId, \count($fields));

        return $fields;
    }

    /**
     * @param list<string> $fieldNames
     */
    private function logStart(int $brandId, array $fieldNames): void
    {
        $this->logger->info('Getting brand custom fields', [
            'brand_id' => $brandId,
            'field_filter' => $fieldNames,
        ]);
    }

    private function logEnd(int $brandId, int $fieldCount): void
    {
        $this->logger->info('Got brand custom fields', [
            'brand_id' => $brandId,
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
