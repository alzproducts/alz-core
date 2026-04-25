<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;

/**
 * Write-side repository for `catalog.custom_field_general_settings`.
 *
 * Rows are identified by `custom_field_definition_id` (the internal UUID of the
 * ShopWired definition). A partial upsert writes only the columns present in
 * the command's {@see SaveCustomFieldGeneralSettingsCommand::$valuesToSet} or
 * {@see SaveCustomFieldGeneralSettingsCommand::$columnsToClear} maps; DB
 * defaults cover the rest on first-create.
 */
interface CustomFieldGeneralSettingsRepositoryInterface
{
    /**
     * Upsert the subset of general-settings columns referenced by the command.
     *
     * Untouched columns keep their existing value on update; on first-create,
     * DB defaults populate them.
     *
     * @throws DatabaseOperationFailedException On constraint violation or schema error
     * @throws DuplicateRecordException When unique constraint violated
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function save(Uuid $definitionInternalId, SaveCustomFieldGeneralSettingsCommand $command): void;
}
