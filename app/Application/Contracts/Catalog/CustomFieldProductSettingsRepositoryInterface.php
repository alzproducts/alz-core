<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;

/**
 * Write-side repository for `catalog.custom_field_product_settings`.
 *
 * Only valid for product-type custom field definitions; the Application use case
 * is expected to guard this invariant before calling save().
 */
interface CustomFieldProductSettingsRepositoryInterface
{
    /**
     * Upsert the subset of product-settings columns named in the command.
     *
     * @throws DatabaseOperationFailedException On constraint violation or schema error
     * @throws DuplicateRecordException When unique constraint violated
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function save(Uuid $definitionInternalId, SaveCustomFieldProductSettingsCommand $command): void;
}
