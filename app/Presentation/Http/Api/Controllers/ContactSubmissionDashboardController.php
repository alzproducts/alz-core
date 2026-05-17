<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\ContactSubmission\UseCases\ListContactSubmissionsUseCase;
use App\Application\ContactSubmission\UseCases\UpsertContactSubmissionAnnotationUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\DTOs\ListContactSubmissionsRequestDTO;
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
        private ListContactSubmissionsUseCase $listUseCase,
        private UpsertContactSubmissionAnnotationUseCase $annotationUseCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function index(ListContactSubmissionsRequestDTO $data): ResourceCollection
    {
        $result = $this->listUseCase->execute($data->toQuery());

        return $this->paginatedResponse($result, ContactSubmissionListResource::class);
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
}
