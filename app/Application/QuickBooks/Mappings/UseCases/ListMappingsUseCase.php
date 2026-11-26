<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingStatusDTO;
use App\Application\QuickBooks\Mappings\Results\MappingListResult;
use App\Application\QuickBooks\OrderSync\Enums\MappingState;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Reads the database only, so it answers with QuickBooks disconnected or its grant revoked.
 */
final readonly class ListMappingsUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksRealmInterface $realm,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): MappingListResult
    {
        $this->logger->info('Listing QuickBooks mappings');
        $realmId = $this->realm->realmId();
        $rows = \array_map(
            static fn(MappingDTO $row): MappingStatusDTO => new MappingStatusDTO($row, MappingState::of($row, $realmId)),
            $this->mappings->all(),
        );
        $this->logger->info('Listed QuickBooks mappings', ['count' => \count($rows)]);

        return new MappingListResult($rows);
    }
}
