<?php

declare(strict_types=1);

namespace App\Application\Catalog\Validators;

use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\CustomFieldNotFoundException;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Validates user-submitted custom field data against the field registry.
 *
 * Uses the strict CustomFieldValueFactory to attempt typed value creation.
 * Unknown fields and type mismatches are caught and wrapped in a result.
 */
final readonly class CustomFieldSubmissionValidator implements ValidatorInterface
{
    /**
     * @param array<string, mixed> $rawFields Submitted field name => value pairs
     */
    public function __construct(
        private CustomFieldValueFactoryInterface $factory,
        private array $rawFields,
    ) {}

    /**
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function validate(): CustomFieldSubmissionResult
    {
        try {
            $this->factory->fromRawFields($this->rawFields);

            return CustomFieldSubmissionResult::valid();
        } catch (CustomFieldNotFoundException $e) {
            return CustomFieldSubmissionResult::unknownField($e->fieldName, $e->itemType);
        } catch (InvalidCustomFieldValueException $e) {
            return CustomFieldSubmissionResult::invalidValue($e->fieldName, $e->expectedType, $e->actualType);
        }
    }
}
