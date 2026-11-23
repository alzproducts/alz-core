<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\UnmatchedMappingDTO;
use App\Application\QuickBooks\Mappings\Resolvers\MappingAutoTargetResolver;
use App\Application\QuickBooks\Mappings\Results\MappingAutoResolveResult;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use Psr\Log\LoggerInterface;

/**
 * Resolves the sandbox company's unresolved keys without prompts.
 */
final readonly class AutoResolveMappingsUseCase
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksEnvironmentInterface $environment,
        private MappingCandidateService $candidates,
        private MappingAutoTargetResolver $targets,
        private RecordMappingPickUseCase $record,
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
     * @throws RecordNotFoundException
     */
    public function execute(): MappingAutoResolveResult
    {
        $this->logger->info('Auto-resolving QuickBooks mappings');
        // intentional: before any token read, since a refresh calls Intuit and writes the connection.
        $result = $this->environment->isSandbox()
            ? $this->resolveAll()
            : new MappingAutoResolveResult(refused: true);

        $this->logger->info('Auto-resolved QuickBooks mappings', [
            'refused' => $result->refused,
            'resolved' => \count($result->resolved),
            'unmatched' => \count($result->unmatched),
        ]);

        return $result;
    }

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
     * @throws RecordNotFoundException
     */
    private function resolveAll(): MappingAutoResolveResult
    {
        $resolved = [];
        $unmatched = $this->withoutRow();
        $candidates = [];
        foreach ($this->mappings->unresolvedKeys($this->environment->realmId()) as $key) {
            $entity = $key->entity();
            $candidates[$entity->value] ??= $this->candidates->candidates($entity);
            $reason = $this->settle($key, $candidates[$entity->value]);
            if ($reason === null) {
                $resolved[] = $key;
            } else {
                $unmatched[] = new UnmatchedMappingDTO($key, $reason);
            }
        }

        return new MappingAutoResolveResult(false, $resolved, $unmatched);
    }

    /**
     * @param list<MappingCandidateDTO> $candidates
     *
     * @return string|null why the key was not recorded
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     * @throws RecordNotFoundException
     */
    private function settle(MappingKey $key, array $candidates): ?string
    {
        $target = $this->targets->resolve($key, $candidates);
        if (\is_string($target)) {
            return $target;
        }

        $this->record->execute($key, $target);

        return null;
    }

    /**
     * @return list<UnmatchedMappingDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function withoutRow(): array
    {
        $stored = \array_map(static fn(MappingDTO $row): MappingKey => $row->key, $this->mappings->all());
        $missing = \array_filter(MappingKey::cases(), static fn(MappingKey $key): bool => !\in_array($key, $stored, true));

        return \array_map(
            static fn(MappingKey $key): UnmatchedMappingDTO => new UnmatchedMappingDTO($key, 'it has no row in quickbooks.mappings'),
            \array_values($missing),
        );
    }
}
