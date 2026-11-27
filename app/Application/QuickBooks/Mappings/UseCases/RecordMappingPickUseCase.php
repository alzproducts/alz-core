<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
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
 * A pick equal to the row writes nothing, so re-runs leave its timestamps alone.
 */
final readonly class RecordMappingPickUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksRealmInterface $realm,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param string|null $actorId the user who made the pick; null from the console
     *
     * @return bool whether the row was written
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException             when the key has no row
     */
    public function execute(MappingKey $key, MappingCandidateDTO $candidate, ?string $actorId = null): bool
    {
        $this->logger->info('Recording QuickBooks mapping pick', ['key' => $key->value]);
        $pick = new MappingPickDTO($key, $candidate->id, $candidate->name, $this->realm->realmId());
        $row = $this->row($key);

        if (self::holds($row, $pick)) {
            $this->logger->info('QuickBooks mapping already holds the pick', ['key' => $key->value]);

            return false;
        }

        $this->mappings->record($pick);
        $this->logRecorded($row, $pick, $actorId);

        return true;
    }

    private function logRecorded(?MappingDTO $row, MappingPickDTO $pick, ?string $actorId): void
    {
        $this->logger->info('Recorded QuickBooks mapping pick', [
            'key' => $pick->key->value,
            'old_ref_id' => $row?->refId,
            'new_ref_id' => $pick->refId,
            'user_id' => $actorId,
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function row(MappingKey $key): ?MappingDTO
    {
        return \array_find($this->mappings->all(), static fn(MappingDTO $row): bool => $row->key === $key);
    }

    private static function holds(?MappingDTO $row, MappingPickDTO $pick): bool
    {
        return $row !== null
            && $row->refId === $pick->refId
            && $row->refName === $pick->refName
            && $row->realmId === $pick->realmId;
    }
}
