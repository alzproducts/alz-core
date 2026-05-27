<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\Conversion;

use App\Application\Conversion\UseCases\ConversionSubmissionOrchestratorService;
use App\Domain\Conversion\CallTracking\Exceptions\AmbiguousCallAttributionException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Api\DTOs\LeadConversionRequestDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/conversions/lead — flag either a contact submission or a call row as a
 * qualified lead. Returns 202; the ad-platform uploads run asynchronously.
 * Exceptions bubble to the global API exception mapper (404 / 400 / 409 / 503).
 */
final readonly class LeadConversionController
{
    public function __construct(
        private ConversionSubmissionOrchestratorService $service,
    ) {}

    /**
     * @throws RecordNotFoundException
     * @throws AmbiguousCallAttributionException
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException
     */
    public function __invoke(LeadConversionRequestDTO $request): JsonResponse
    {
        $this->service->execute(Uuid::fromTrusted($request->id), $request->isPotentialQuote);

        return new JsonResponse(
            ['message' => 'Lead conversion queued'],
            Response::HTTP_ACCEPTED,
        );
    }
}
