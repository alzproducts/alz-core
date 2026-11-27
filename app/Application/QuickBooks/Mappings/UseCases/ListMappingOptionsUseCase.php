<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingOptionDTO;
use App\Application\QuickBooks\Mappings\Results\MappingOptionsResult;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\OrderSync\Enums\MappingState;
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
 * The key's active candidates, marking the one its row holds for the configured realm.
 */
final readonly class ListMappingOptionsUseCase
{
    public function __construct(
        private MappingCandidateService $candidates,
        private QuickBooksMappingRepositoryInterface $mappings,
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
    public function execute(MappingKey $key, Deadline $deadline): MappingOptionsResult
    {
        $this->logger->info('Listing QuickBooks mapping options', ['key' => $key->value]);
        $currentId = $this->currentRefId($key);
        $options = [];

        foreach ($this->candidates->candidates($key->entity(), $deadline) as $candidate) {
            if ($candidate->active) {
                $options[] = new MappingOptionDTO($candidate, $candidate->id === $currentId);
            }
        }

        $this->logger->info('Listed QuickBooks mapping options', ['count' => \count($options)]);

        return new MappingOptionsResult($options);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function currentRefId(MappingKey $key): ?string
    {
        $realmId = $this->realm->realmId();
        $row = \array_find($this->mappings->all(), static fn(MappingDTO $row): bool => $row->key === $key);

        return $row !== null && MappingState::of($row, $realmId) === MappingState::Resolved ? $row->refId : null;
    }
}
