<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Upsert the product settings for a custom field definition using partial-update
 * semantics. Rejects writes when the definition's item_type is not `product`.
 *
 * Addressed by internal UUID — the canonical identifier for settings rows.
 */
final readonly class SaveCustomFieldProductSettingsUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private CustomFieldProductSettingsRepositoryInterface $productSettingsRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the internal UUID
     * @throws ProductSettingsNotApplicableException When the definition is not a product-type field
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        Uuid $internalId,
        SaveCustomFieldProductSettingsCommand $command,
    ): ConfiguredFieldDefinition {
        $this->logger->info('Saving custom field product settings', [
            'definition_internal_id' => $internalId->value,
            'fields_changed' => $command->touchedKeys,
        ]);

        $definition = $this->customFieldRepository->findByInternalId($internalId);
        self::assertProductField($definition);

        $this->productSettingsRepository->save($internalId, $command);

        $this->logger->info('Saved custom field product settings', [
            'definition_internal_id' => $internalId->value,
        ]);

        return $this->customFieldRepository->findByInternalId($internalId);
    }

    /**
     * @throws ProductSettingsNotApplicableException
     */
    private static function assertProductField(ConfiguredFieldDefinition $definition): void
    {
        if (! $definition->base->isProductField()) {
            throw new ProductSettingsNotApplicableException(
                definitionExternalId: $definition->base->id,
                itemType: $definition->base->itemType,
            );
        }
    }
}
