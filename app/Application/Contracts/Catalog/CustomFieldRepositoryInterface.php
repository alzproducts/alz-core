<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for custom field definitions — owns both sync persistence and enriched reads.
 *
 * Write path (shopwired schema only): accepts raw {@see CustomFieldDefinition} entities
 * from the ShopWired sync and upserts them into `shopwired.custom_field_definitions`.
 * Sync never touches local catalog settings.
 *
 * Read path (cross-schema): joins `shopwired.custom_field_definitions` with
 * `catalog.custom_field_{general,product}_settings` and returns {@see ConfiguredFieldDefinition}.
 * Callers never see a raw definition on the read surface.
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
    public function findByName(string $name): ?ConfiguredFieldDefinition;

    /**
     * Get all custom field definitions for a specific item type.
     *
     * @return list<ConfiguredFieldDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findByItemType(CustomFieldItemType $itemType): array;

    /**
     * Get all custom field definitions.
     *
     * @return list<ConfiguredFieldDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findAll(): array;
}
