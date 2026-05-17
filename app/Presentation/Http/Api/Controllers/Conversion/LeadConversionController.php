<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\Conversion;

use App\Application\Conversion\UseCases\SubmitLeadConversionUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\LeadConversionRequestDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/conversions/lead — flag a contact submission as a qualified lead.
 *
 * Returns 202 on success. The Google Ads upload runs asynchronously via ProcessLeadConversionJob.
 * Exceptions bubble to the global API exception mapper (404 / 400 / 409 / 503).
 */
final readonly class LeadConversionController
{
    public function __construct(
        private SubmitLeadConversionUseCase $useCase,
    ) {}

    /**
     * @throws RecordNotFoundException
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(LeadConversionRequestDTO $request): JsonResponse
    {
        $this->useCase->execute($request->submissionId);

        return new JsonResponse(
            ['message' => 'Lead conversion queued'],
            Response::HTTP_ACCEPTED,
        );
    }
}
