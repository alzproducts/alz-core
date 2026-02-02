<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\ContactForm;

use App\Application\ContactSubmission\UseCases\SubmitContactFormUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\ContactForm\ContactSubmissionFactory;
use App\Presentation\Http\Requests\ContactFormRequest;
use App\Presentation\Jobs\ContactForm\ProcessContactSubmissionJob;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles contact form submissions from the frontend.
 *
 * Flow:
 * 1. Validate request (handled by FormRequest)
 * 2. Check honeypot → silent 200 if triggered (handled by RejectHoneypotMiddleware)
 * 3. Build domain object → save to DB
 * 4. Dispatch async job for HelpScout processing
 * 5. Return submission ID
 */
final readonly class ContactFormController
{
    public function __construct(
        private ContactSubmissionFactory $factory,
        private SubmitContactFormUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     * @throws InvalidEnumValueException When enum values don't match expected values
     */
    public function __invoke(ContactFormRequest $request): JsonResponse
    {
        // Build domain object from request
        $submission = $this->factory->fromRequest($request);

        // Save to database and get IDs
        $result = $this->useCase->execute($submission);

        // Dispatch async job for HelpScout processing
        ProcessContactSubmissionJob::dispatch(
            $result->submissionId,
            $result->actionId,
        );

        return new JsonResponse(
            ['id' => $result->submissionId],
            Response::HTTP_OK,
        );
    }
}
