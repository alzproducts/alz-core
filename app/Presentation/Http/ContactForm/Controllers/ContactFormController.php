<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\Controllers;

use App\Application\ContactSubmission\UseCases\SubmitContactFormUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\Responses\ContactSubmissionAcceptedResponseDTO;
use App\Presentation\Http\ContactForm\DTOs\ContactFormRequestDTO;
use App\Presentation\Http\ContactForm\Mappers\ContactSubmissionMapper;
use Illuminate\Http\Request;

/**
 * Handles contact form submissions from the frontend.
 *
 * Flow:
 * 1. Check honeypot → silent 200 if triggered (handled by RejectHoneypotMiddleware)
 * 2. Validate + parse request (handled by Spatie Data DTO)
 * 3. Build domain object → save to DB
 * 4. Dispatch async job for HelpScout processing
 * 5. Return submission ID
 */
final readonly class ContactFormController
{
    public function __construct(
        private ContactSubmissionMapper $mapper,
        private SubmitContactFormUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException On insert failure (permanent)
     * @throws DuplicateRecordException On unique constraint violation (permanent)
     * @throws ExternalServiceUnavailableException On transient database failure (retry)
     * @throws InvalidEnumValueException When enum values don't match expected values
     * @throws InvalidFormatException
     */
    public function __invoke(Request $request): ContactSubmissionAcceptedResponseDTO
    {
        // Validate + type request data in one step
        $data = ContactFormRequestDTO::from($request);

        // Transform to domain objects
        $submission = $this->mapper->toDomain($data, $request->ip() ?? '0.0.0.0');

        // Save to database, dispatch async HelpScout processing
        $result = $this->useCase->execute($submission);

        return ContactSubmissionAcceptedResponseDTO::from($result->submissionId);
    }
}
