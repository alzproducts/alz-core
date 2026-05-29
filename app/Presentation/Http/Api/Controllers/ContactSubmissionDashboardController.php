<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\ContactSubmission\UseCases\DismissContactSubmissionUseCase;
use App\Application\ContactSubmission\UseCases\ListContactSubmissionsByViewUseCase;
use App\Application\ContactSubmission\UseCases\MarkNoQuoteExpectedUseCase;
use App\Application\ContactSubmission\UseCases\UpsertContactSubmissionAnnotationUseCase;
use App\Domain\ContactSubmission\Enums\ContactSubmissionView;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\ContactSubmission\Exceptions\OperationNotSupportedForSourceException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\DTOs\ListContactSubmissionsViewQueryDTO;
use App\Presentation\Http\Api\DTOs\UpsertContactSubmissionAnnotationRequestDTO;
use App\Presentation\Http\Api\Resources\ContactSubmissionListResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff dashboard controller for contact submissions.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws RecordNotFoundException
 */
final readonly class ContactSubmissionDashboardController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListContactSubmissionsByViewUseCase $listByViewUseCase,
        private UpsertContactSubmissionAnnotationUseCase $annotationUseCase,
        private DismissContactSubmissionUseCase $dismissUseCase,
        private MarkNoQuoteExpectedUseCase $noQuoteUseCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function triage(ListContactSubmissionsViewQueryDTO $data): ResourceCollection
    {
        return $this->paginatedView(ContactSubmissionView::Triage, $data);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function awaitingQuote(ListContactSubmissionsViewQueryDTO $data): ResourceCollection
    {
        return $this->paginatedView(ContactSubmissionView::AwaitingQuote, $data);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function failed(ListContactSubmissionsViewQueryDTO $data): ResourceCollection
    {
        return $this->paginatedView(ContactSubmissionView::Failed, $data);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function completed(ListContactSubmissionsViewQueryDTO $data): ResourceCollection
    {
        return $this->paginatedView(ContactSubmissionView::Completed, $data);
    }

    /**
     * @throws RecordNotFoundException When the contact submission does not exist
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertAnnotation(string $id, UpsertContactSubmissionAnnotationRequestDTO $data): JsonResponse
    {
        $this->annotationUseCase->execute($data->toCommand(new Guid($id)));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws RecordNotFoundException When the conversion row does not exist
     * @throws InvalidActionStageException When the row is past Triage stage
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function dismiss(string $id): JsonResponse
    {
        $this->dismissUseCase->execute(new Guid($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws RecordNotFoundException When the conversion row does not exist
     * @throws OperationNotSupportedForSourceException When the row is a call (form-only endpoint)
     * @throws InvalidActionStageException When the row is not in Awaiting Quote stage
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function markNoQuoteExpected(string $id): JsonResponse
    {
        $this->noQuoteUseCase->execute(new Guid($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function paginatedView(ContactSubmissionView $view, ListContactSubmissionsViewQueryDTO $data): ResourceCollection
    {
        $result = $this->listByViewUseCase->execute($view, PageRequest::from($data->page, $data->per_page));

        return $this->paginatedResponse($result, ContactSubmissionListResource::class);
    }
}
