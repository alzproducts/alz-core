<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\OrderSync\UseCases\RequestOrderRangeSyncUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use App\Presentation\Http\Api\DTOs\SyncQuickBooksOrderRangeRequestDTO;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @throws AuthenticationExpiredException
 * @throws CorruptApiKeyException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidApiResponseException
 * @throws KeyEncryptionFailedException
 * @throws LockAcquisitionException
 * @throws QuickBooksMappingsUnresolvedException
 * @throws ValidationFailedException
 */
final readonly class SyncQuickBooksOrderRangeController
{
    public function __construct(
        private RequestOrderRangeSyncUseCase $requestRangeSync,
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
     * @throws QuickBooksMappingsUnresolvedException
     * @throws ValidationFailedException
     */
    public function __invoke(SyncQuickBooksOrderRangeRequestDTO $data, Request $request): JsonResponse
    {
        $accepted = $this->requestRangeSync->execute($data->toRange(), Str::uuid()->toString(), self::arrivedAt($request));

        return new JsonResponse([
            'message' => 'QuickBooks order sync queued.',
            'run_id' => $accepted->runId,
            'candidate_count' => $accepted->candidateCount,
        ], JsonResponse::HTTP_ACCEPTED);
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
