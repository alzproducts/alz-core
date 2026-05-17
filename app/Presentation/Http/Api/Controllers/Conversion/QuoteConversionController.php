<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers\Conversion;

use App\Application\Conversion\UseCases\SubmitQuoteConversionUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\QuoteConversionRequestDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/conversions/quote — flag a contact submission as a quote issued.
 *
 * Returns 202 on success. The Google Ads upload runs asynchronously via
 * `ProcessQuoteConversionJob`.
 * Exceptions bubble to the global API exception mapper (404 / 422 / 409 / 503).
 *
 * Requires a Completed `LeadReceived` action on the same submission — the use
 * case validates this and raises `InsufficientDataException` (→ 422) otherwise.
 */
final readonly class QuoteConversionController
{
    public function __construct(
        private SubmitQuoteConversionUseCase $useCase,
    ) {}

    /**
     * @throws RecordNotFoundException
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(QuoteConversionRequestDTO $request): JsonResponse
    {
        $this->useCase->execute($request->submissionId, $request->value, $request->convertedAt);

        return new JsonResponse(
            ['message' => 'Quote conversion queued'],
            Response::HTTP_ACCEPTED,
        );
    }
}
