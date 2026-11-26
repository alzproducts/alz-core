<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\Mappings\UseCases\ListMappingOptionsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\RecordMappingPickUseCase;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
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
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Presentation\Http\Api\DTOs\UpdateQuickBooksMappingRequestDTO;
use App\Presentation\Http\Api\Resources\QuickBooksMappingResource;
use App\Presentation\Http\QuickBooks\QuickBooksMappingDeadlineFactory;

/**
 * @throws AuthenticationExpiredException
 * @throws CorruptApiKeyException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidApiResponseException
 * @throws KeyEncryptionFailedException
 * @throws LockAcquisitionException
 * @throws QuickBooksFaultException
 * @throws RecordNotFoundException
 * @throws UnexpectedApiResultException
 * @throws ValidationFailedException
 */
final readonly class QuickBooksMappingUpdateController
{
    public function __construct(
        private ListMappingOptionsUseCase $listOptions,
        private RecordMappingPickUseCase $recordPick,
        private ListMappingsUseCase $listMappings,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksFaultException
     * @throws RecordNotFoundException
     * @throws UnexpectedApiResultException
     * @throws ValidationFailedException
     */
    public function __invoke(MappingKey $key, UpdateQuickBooksMappingRequestDTO $data, AuthenticatedUser $user): QuickBooksMappingResource
    {
        $candidate = $this->listOptions->execute($key, QuickBooksMappingDeadlineFactory::fromNow())->pick($data->ref_id);
        $this->recordPick->execute($key, $candidate, $user->id);

        return new QuickBooksMappingResource($this->listMappings->execute()->row($key));
    }
}
