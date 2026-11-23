<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\Services;

use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
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

/**
 * Candidate reads and creates under the stored connection: a token per call, never a rejected one twice.
 */
final readonly class MappingCandidateService
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksMappingCandidatesInterface $candidates,
    ) {}

    /**
     * @return list<MappingCandidateDTO> active and inactive
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
    public function candidates(MappingEntity $entity): array
    {
        $accessToken = $this->tokens->accessToken();

        try {
            return $this->candidates->candidates($accessToken, $entity);
        } catch (AuthenticationExpiredException $e) {
            $this->tokens->forgetAccessToken();

            throw $e;
        }
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException            when QBO refuses the create
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function create(MappingEntity $entity, string $name): MappingCandidateDTO
    {
        $accessToken = $this->tokens->accessToken();

        try {
            return $this->candidates->create($accessToken, $entity, $name);
        } catch (AuthenticationExpiredException $e) {
            $this->tokens->forgetAccessToken();

            throw $e;
        }
    }
}
