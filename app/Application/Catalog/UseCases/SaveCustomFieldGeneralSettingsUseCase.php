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
use Psr\Log\LoggerInterface;

/**
 * Upsert the general settings for a custom field definition using partial-update
 * semantics. The {@see SaveCustomFieldGeneralSettingsCommand} already carries the
 * merged values for touched columns; untouched columns are left alone by the repo.
 */
final readonly class SaveCustomFieldGeneralSettingsUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private CustomFieldGeneralSettingsRepositoryInterface $generalSettingsRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the external ID
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        int $definitionExternalId,
        SaveCustomFieldGeneralSettingsCommand $command,
    ): ConfiguredFieldDefinition {
        $this->logger->info('Saving custom field general settings', [
            'definition_id' => $definitionExternalId,
            'fields_changed' => $command->touchedKeys,
        ]);

        $internalId = $this->customFieldRepository->findInternalIdByExternalId($definitionExternalId);
        $this->generalSettingsRepository->save($internalId, $command);

        $this->logger->info('Saved custom field general settings', [
            'definition_id' => $definitionExternalId,
        ]);

        return $this->customFieldRepository->findByExternalId($definitionExternalId);
    }
}
