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
use App\Domain\Shared\ValueObjects\Deadline;
use App\Presentation\Http\Api\DTOs\SyncQuickBooksOrdersRequestDTO;
use App\Presentation\Http\Api\Resources\QuickBooksOrderSyncResultsResource;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    private const int DEADLINE_SECONDS = 25;

    public function __construct(
        private SyncOrdersByReferenceUseCase $syncOrders,
    ) {}

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
    public function __invoke(SyncQuickBooksOrdersRequestDTO $data, Request $request): JsonResponse
    {
        $deadline = Deadline::after(self::arrivedAt($request), self::DEADLINE_SECONDS);
        $results = $this->syncOrders->execute($data->references, $data->force_retry, $deadline);

        // intentional: resolved here, as a returned resource would wrap the body in `data`.
        return new JsonResponse(new QuickBooksOrderSyncResultsResource($results)->resolve($request));
    }

    /**
     * When the server received the request, before any middleware ran.
     */
    private static function arrivedAt(Request $request): DateTimeImmutable
    {
        $arrivedAt = $request->server('REQUEST_TIME_FLOAT');

        return \is_numeric($arrivedAt) ? DateTimeImmutable::createFromTimestamp((float) $arrivedAt) : new DateTimeImmutable();
    }
}
