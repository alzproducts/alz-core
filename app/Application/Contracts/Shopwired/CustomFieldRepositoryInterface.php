<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for ShopWired custom field definition persistence.
 *
 * @extends RepositoryWriteInterface<CustomFieldDefinition>
 */
interface CustomFieldRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Find a custom field definition by its name.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findByName(string $name): ?CustomFieldDefinition;

    /**
     * Get all custom field definitions for a specific item type.
     *
     * @return list<CustomFieldDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findByItemType(CustomFieldItemType $itemType): array;

    /**
     * Get all custom field definitions.
     *
     * @return list<CustomFieldDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findAll(): array;
}
