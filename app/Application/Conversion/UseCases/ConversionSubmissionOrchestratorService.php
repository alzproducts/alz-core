<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Conversion\CallTracking\UseCases\SubmitCallLeadConversionUseCase;
use App\Domain\Conversion\CallTracking\Exceptions\AmbiguousCallAttributionException;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Source-agnostic entry point for lead-conversion submission — keeps the controller
 * and admin frontend unaware of whether the UUID belongs to a contact form or a call row.
 */
final readonly class ConversionSubmissionOrchestratorService
{
    public function __construct(
        private ContactSubmissionRepositoryInterface $contactSubmissionRepository,
        private CallTrackingCallRepositoryInterface $callRepository,
        private CallTrackingVisitRepositoryInterface $visitRepository,
        private SubmitLeadConversionUseCase $formUseCase,
        private SubmitCallLeadConversionUseCase $callUseCase,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RecordNotFoundException When the UUID matches neither a contact submission nor a call, or when a contact submission is deleted mid-flight
     * @throws AmbiguousCallAttributionException When a call exists but multiple visits match within the attribution window
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException
     */
    public function execute(Uuid $id, bool $isPotentialQuote): void
    {
        $this->logger->info('Submitting conversion', [
            'id' => $id->value,
            'is_potential_quote' => $isPotentialQuote,
        ]);

        if ($this->tryFormPath($id, $isPotentialQuote)) {
            $this->logger->info('Conversion routed to form pipeline', ['id' => $id->value]);

            return;
        }

        $this->routeCallPath($id, $isPotentialQuote);

        $this->logger->info('Conversion routed to call pipeline', ['id' => $id->value]);
    }

    /**
     * @throws RecordNotFoundException When the form use case finds the submission gone mid-flight
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws MalformedStoredDataException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function tryFormPath(Uuid $id, bool $isPotentialQuote): bool
    {
        if (! $this->contactSubmissionRepository->existsById($id->value)) {
            return false;
        }

        $this->formUseCase->execute($id, $isPotentialQuote);

        return true;
    }

    /**
     * @throws RecordNotFoundException When no call exists for the UUID
     * @throws AmbiguousCallAttributionException
     * @throws InsufficientDataException
     * @throws DuplicateRecordException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidFormatException
     */
    private function routeCallPath(Uuid $id, bool $isPotentialQuote): void
    {
        try {
            $call = $this->callRepository->findById($id);
        } catch (RecordNotFoundException $e) {
            throw new RecordNotFoundException('Conversion', $id->value, previous: $e);
        }

        $visit = $this->visitRepository->findByCallId($id);

        $this->callUseCase->execute(
            $visit,
            PhoneNumberE164::from($call->callerPhoneNumber),
            $isPotentialQuote,
        );
    }
}
