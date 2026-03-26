<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\CustomFields\Exceptions\CustomFieldNotFoundException;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Strict factory for creating typed custom field values from raw data.
 *
 * Unlike ProductCustomFieldFactory (which logs and skips unknown fields),
 * this factory throws on ALL errors — suitable for validating user input.
 */
interface CustomFieldValueFactoryInterface
{
    /**
     * @param array<string, mixed> $rawFields Field name => value pairs
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws CustomFieldNotFoundException When a field name is not in the registry
     * @throws InvalidCustomFieldValueException When a value type mismatches the definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function fromRawFields(array $rawFields): array;
}
