<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\OrderSync\UseCases\ListFailedOrderSyncsUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Presentation\Http\Api\DTOs\ListFailedQuickBooksOrdersQueryDTO;
use App\Presentation\Http\Api\Resources\FailedQuickBooksOrderResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * @throws AuthenticationExpiredException
 * @throws CorruptApiKeyException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidApiResponseException
 * @throws KeyEncryptionFailedException
 * @throws LockAcquisitionException
 */
final readonly class ListFailedQuickBooksOrdersController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListFailedOrderSyncsUseCase $listFailed,
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
     */
    public function __invoke(ListFailedQuickBooksOrdersQueryDTO $data): ResourceCollection
    {
        return $this->paginatedResponse(
            $this->listFailed->execute($data->status, PageRequest::from($data->page, $data->per_page)),
            FailedQuickBooksOrderResource::class,
        );
    }
}
