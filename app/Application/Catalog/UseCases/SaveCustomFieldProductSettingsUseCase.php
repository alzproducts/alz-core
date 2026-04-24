<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Application\Catalog\Results\CustomFieldResolutionResult;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Upsert the product settings for a custom field definition using partial-update
 * semantics. Rejects writes when the definition's item_type is not `product`.
 */
final readonly class SaveCustomFieldProductSettingsUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private CustomFieldProductSettingsRepositoryInterface $productSettingsRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the external ID
     * @throws ProductSettingsNotApplicableException When the definition is not a product-type field
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        int $definitionExternalId,
        SaveCustomFieldProductSettingsCommand $command,
    ): ConfiguredFieldDefinition {
        $this->logger->info('Saving custom field product settings', [
            'definition_id' => $definitionExternalId,
            'fields_changed' => $command->touchedKeys,
        ]);

        $resolved = $this->customFieldRepository->findEnrichedWithInternalId($definitionExternalId);
        self::assertProductField($resolved);

        $this->productSettingsRepository->save($resolved->internalId, $command);

        $this->logger->info('Saved custom field product settings', [
            'definition_id' => $definitionExternalId,
        ]);

        return $this->customFieldRepository->findByExternalId($definitionExternalId);
    }

    /**
     * @throws ProductSettingsNotApplicableException
     */
    private static function assertProductField(CustomFieldResolutionResult $resolved): void
    {
        if (! $resolved->definition->base->isProductField()) {
            throw new ProductSettingsNotApplicableException(
                definitionExternalId: $resolved->definition->base->id,
                itemType: $resolved->definition->base->itemType,
            );
        }
    }
}
