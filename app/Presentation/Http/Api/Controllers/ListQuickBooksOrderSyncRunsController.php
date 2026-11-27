<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\QuickBooks\OrderSync\UseCases\ListOrderSyncRunsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Presentation\Http\Api\DTOs\ListQuickBooksOrderSyncRunsQueryDTO;
use App\Presentation\Http\Api\Resources\QuickBooksOrderSyncRunResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
final readonly class ListQuickBooksOrderSyncRunsController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListOrderSyncRunsUseCase $listRuns,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(ListQuickBooksOrderSyncRunsQueryDTO $data): ResourceCollection
    {
        return $this->paginatedResponse(
            $this->listRuns->execute(PageRequest::from($data->page, $data->per_page)),
            QuickBooksOrderSyncRunResource::class,
        );
    }
}
