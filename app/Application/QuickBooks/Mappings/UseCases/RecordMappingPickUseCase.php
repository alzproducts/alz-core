<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Stores a pick for the configured realm; a pick equal to the row writes nothing, so re-runs leave its timestamps alone.
 */
final readonly class RecordMappingPickUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksEnvironmentInterface $environment,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return bool whether the row was written
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException             when the key has no row
     */
    public function execute(MappingKey $key, MappingCandidateDTO $candidate): bool
    {
        $this->logger->info('Recording QuickBooks mapping pick', ['key' => $key->value]);
        $pick = new MappingPickDTO($key, $candidate->id, $candidate->name, $this->environment->realmId());
        $written = !$this->holds($pick);

        if ($written) {
            $this->mappings->record($pick);
        }

        $this->logger->info('Recorded QuickBooks mapping pick', ['written' => $written]);

        return $written;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function holds(MappingPickDTO $pick): bool
    {
        return \array_any(
            $this->mappings->all(),
            static fn(MappingDTO $row): bool => $row->key === $pick->key
                && $row->refId === $pick->refId
                && $row->refName === $pick->refName
                && $row->realmId === $pick->realmId,
        );
    }
}
