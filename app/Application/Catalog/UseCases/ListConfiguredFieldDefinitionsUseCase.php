<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * List all custom field definitions enriched with their local settings blocks.
 */
final readonly class ListConfiguredFieldDefinitionsUseCase
{
    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<ConfiguredFieldDefinition>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): array
    {
        $this->logger->info('Listing custom field definitions');

        $definitions = $this->customFieldRepository->findAll();

        $this->logger->info('Listed custom field definitions', [
            'count' => \count($definitions),
        ]);

        return $definitions;
    }
}
