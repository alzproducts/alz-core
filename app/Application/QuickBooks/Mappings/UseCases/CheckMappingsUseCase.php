<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingFindingDTO;
use App\Application\QuickBooks\Mappings\Results\MappingCheckResult;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingFindingKind;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;

/**
 * Writes no mapping row, though a token refresh may be stored.
 */
final readonly class CheckMappingsUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private MappingCandidateService $candidates,
        private QuickBooksRealmInterface $realm,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function execute(?Deadline $deadline = null): MappingCheckResult
    {
        $this->logger->info('Checking QuickBooks mappings');
        $unresolved = $this->mappings->unresolvedKeys($this->realm->realmId());
        $findings = $this->findings($unresolved, $this->rowsByKey(), $deadline);
        $this->logger->info('Checked QuickBooks mappings', ['findings' => \count($findings)]);

        return new MappingCheckResult($findings);
    }

    /**
     * @param list<MappingKey>          $unresolved
     * @param array<string, MappingDTO> $rows
     *
     * @return list<MappingFindingDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    private function findings(array $unresolved, array $rows, ?Deadline $deadline): array
    {
        $findings = [];
        $candidates = [];
        foreach (MappingKey::cases() as $key) {
            $row = $rows[$key->value] ?? null;
            if ($row?->refId === null || \in_array($key, $unresolved, true)) {
                $findings[] = self::unresolved($key, $row);

                continue;
            }

            $entity = $key->entity();
            $candidates[$entity->value] ??= $this->candidates->candidates($entity, $deadline);
            $findings = [...$findings, ...self::compare($row, $candidates[$entity->value])];
        }

        return $findings;
    }

    /**
     * @return array<string, MappingDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function rowsByKey(): array
    {
        $rows = [];
        foreach ($this->mappings->all() as $row) {
            $rows[$row->key->value] = $row;
        }

        return $rows;
    }

    private static function unresolved(MappingKey $key, ?MappingDTO $row): MappingFindingDTO
    {
        return new MappingFindingDTO(
            $key,
            $row?->refId === null ? MappingFindingKind::Unresolved : MappingFindingKind::OtherRealm,
            $row?->refName,
        );
    }

    /**
     * @param list<MappingCandidateDTO> $candidates
     *
     * @return list<MappingFindingDTO>
     */
    private static function compare(MappingDTO $row, array $candidates): array
    {
        $current = \array_find($candidates, static fn(MappingCandidateDTO $candidate): bool => $candidate->id === $row->refId);
        if ($current === null) {
            return [new MappingFindingDTO($row->key, MappingFindingKind::NotFound, $row->refName)];
        }

        $findings = [];
        if ($current->name !== $row->refName) {
            $findings[] = new MappingFindingDTO($row->key, MappingFindingKind::NameDrift, $row->refName, $current->name);
        }
        if (!$current->active) {
            $findings[] = new MappingFindingDTO($row->key, MappingFindingKind::Inactive, $row->refName, $current->name);
        }

        return $findings;
    }
}
