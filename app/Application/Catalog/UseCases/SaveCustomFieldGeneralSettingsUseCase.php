<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Application\Contracts\Catalog\CustomFieldGeneralSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Upsert the general settings for a custom field definition using partial-update
 * semantics. The {@see SaveCustomFieldGeneralSettingsCommand} already carries the
 * merged values for touched columns; untouched columns are left alone by the repo.
 *
 * Addressed by internal UUID — the canonical identifier for settings rows.
 */
final readonly class SaveCustomFieldGeneralSettingsUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private CustomFieldGeneralSettingsRepositoryInterface $generalSettingsRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the internal UUID (refresh load)
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        Uuid $internalId,
        SaveCustomFieldGeneralSettingsCommand $command,
    ): ConfiguredFieldDefinition {
        $this->logger->info('Saving custom field general settings', [
            'definition_internal_id' => $internalId->value,
            'fields_changed' => $command->touchedKeys,
        ]);

        $this->generalSettingsRepository->save($internalId, $command);

        $this->logger->info('Saved custom field general settings', [
            'definition_internal_id' => $internalId->value,
        ]);

        return $this->customFieldRepository->findByInternalId($internalId);
    }
}
