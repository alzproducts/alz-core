<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\OrderSync\UseCases\SyncOrdersByReferenceUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use App\Presentation\Http\Api\DTOs\SyncQuickBooksOrdersRequestDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * @throws AuthenticationExpiredException
 * @throws CorruptApiKeyException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidApiResponseException
 * @throws InvalidConfigurationException
 * @throws KeyEncryptionFailedException
 * @throws LockAcquisitionException
 * @throws QuickBooksMappingsUnresolvedException
 * @throws ValidationFailedException
 */
final readonly class SyncQuickBooksOrdersController
{
    public function __construct(
        private SyncOrdersByReferenceUseCase $syncOrders,
    ) {}

    /**
     * `run_id` is a log correlation id only: no endpoint takes it.
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidConfigurationException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksMappingsUnresolvedException
     * @throws ValidationFailedException
     */
    public function __invoke(SyncQuickBooksOrdersRequestDTO $data): JsonResponse
    {
        $runId = Str::uuid()->toString();
        $this->syncOrders->execute($data->references, $data->force_retry, $runId);

        return new JsonResponse(['message' => 'QuickBooks order sync queued.', 'run_id' => $runId], JsonResponse::HTTP_ACCEPTED);
    }
}
