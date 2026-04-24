<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Fetch a single custom field definition enriched with its local settings blocks.
 */
final readonly class GetConfiguredFieldDefinitionUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the external ID
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $definitionExternalId): ConfiguredFieldDefinition
    {
        $this->logger->info('Getting custom field definition', [
            'definition_id' => $definitionExternalId,
        ]);

        $definition = $this->customFieldRepository->findByExternalId($definitionExternalId);

        $this->logger->info('Got custom field definition', [
            'definition_id' => $definitionExternalId,
            'name' => $definition->base->name,
        ]);

        return $definition;
    }
}
