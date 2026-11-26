<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\Mappings\UseCases\CheckMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListMappingOptionsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListMappingsUseCase;
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
use App\Presentation\Http\Api\Resources\QuickBooksMappingCandidateResource;
use App\Presentation\Http\Api\Resources\QuickBooksMappingFindingResource;
use App\Presentation\Http\Api\Resources\QuickBooksMappingResource;
use App\Presentation\Http\QuickBooks\QuickBooksMappingDeadlineFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
 * @throws UnexpectedApiResultException
 */
final readonly class QuickBooksMappingController
{
    public function __construct(
        private ListMappingsUseCase $listMappings,
        private ListMappingOptionsUseCase $listOptions,
        private CheckMappingsUseCase $checkMappings,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function index(): AnonymousResourceCollection
    {
        return QuickBooksMappingResource::collection($this->listMappings->execute()->rows);
    }

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
     * @throws UnexpectedApiResultException
     */
    public function candidates(MappingKey $key, Request $request): AnonymousResourceCollection
    {
        $options = $this->listOptions->execute($key, QuickBooksMappingDeadlineFactory::forRequest($request));

        return QuickBooksMappingCandidateResource::collection($options->options);
    }

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
     * @throws UnexpectedApiResultException
     */
    public function check(Request $request): AnonymousResourceCollection
    {
        $result = $this->checkMappings->execute(QuickBooksMappingDeadlineFactory::forRequest($request));

        return QuickBooksMappingFindingResource::collection($result->findings);
    }
}
