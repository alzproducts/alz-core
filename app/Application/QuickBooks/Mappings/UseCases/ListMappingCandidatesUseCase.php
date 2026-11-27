<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\UseCases;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
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

final readonly class ListMappingCandidatesUseCase
{
    public function __construct(
        private MappingCandidateService $candidates,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<MappingCandidateDTO> active only
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
    public function execute(MappingKey $key, Deadline $deadline): array
    {
        $this->logger->info('Listing QuickBooks mapping candidates', ['key' => $key->value]);
        $active = \array_values(\array_filter(
            $this->candidates->candidates($key->entity(), $deadline),
            static fn(MappingCandidateDTO $candidate): bool => $candidate->active,
        ));
        $this->logger->info('Listed QuickBooks mapping candidates', ['count' => \count($active)]);

        return $active;
    }
}
