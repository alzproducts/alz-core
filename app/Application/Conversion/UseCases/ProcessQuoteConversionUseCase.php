<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\QuoteConversionDetailsDTO;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\Transformers\ConversionUploadTransformer;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\ContactSubmission\ValueObjects\ActionAttempts;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Data\AbstractDataException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Uploads a quote conversion to an ad platform (Google, Bing, …), resolved from
 * the passed {@see AdPlatform} via the adapter seam.
 *
 * Runs only on a claim of a due `pending` action, with the claim outcomes of
 * {@see ProcessLeadConversionUseCase}. The upload carries the staff-provided
 * value and conversion time from the job payload.
 */
final readonly class ProcessQuoteConversionUseCase
{
    /**
     * Sentinel passed to `completeClaim()` since ad platforms return no receipt ID for
     * uploaded conversions — the action row still requires a non-null external reference.
     */
    private const string COMPLETION_RECEIPT = 'uploaded';

    public function __construct(
        private ContactSubmissionRepositoryInterface $submissionRepository,
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private AdPlatformAdapterResolverService $adapterResolver,
        private HandleQuoteConversionFailureUseCase $failureHandler,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ActionTypeMismatchException When the action belongs to another pipeline
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable (row returned to pending)
     * @throws PermanentApiFailure When the ad platform rejects the upload or its credentials (row failed)
     * @throws AbstractDataException When the click ID or the queued convertedAt is unusable (row failed)
     * @throws UnsupportedConversionTypeException When the platform does not support quote conversions (row failed)
     * @throws DatabaseOperationFailedException When DB update fails
     * @throws DuplicateRecordException
     */
    public function execute(string $submissionId, string $actionId, QuoteConversionDetailsDTO $details, AdPlatform $platform): void
    {
        $this->logProcessing($submissionId, $actionId, $details, $platform);

        $claim = $this->claimDueAction($submissionId, $actionId);
        if ($claim === null) {
            return;
        }

        try {
            $this->upload($submissionId, $details, $platform);
        } catch (TransientApiFailure|PermanentApiFailure|AbstractDataException|UnsupportedConversionTypeException $e) {
            $this->handleUploadFailure($submissionId, $actionId, $claim, $e);

            return;
        }

        $this->complete($submissionId, $actionId, $platform, $claim->token);
    }

    private function logProcessing(string $submissionId, string $actionId, QuoteConversionDetailsDTO $details, AdPlatform $platform): void
    {
        $this->logger->info('Processing quote conversion', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'value' => $details->value,
            'converted_at' => $details->convertedAt,
            'platform' => $platform->value,
        ]);
    }

    /**
     * @return ActionClaimDTO|null null when there is nothing to run, or the attempt budget was spent and the row failed
     *
     * @throws ActionTypeMismatchException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function claimDueAction(string $submissionId, string $actionId): ?ActionClaimDTO
    {
        $claim = $this->claim($submissionId, $actionId);

        if ($claim !== null && new ActionAttempts($claim->attempts)->isPastLimit()) {
            $this->failureHandler->execute($submissionId, $actionId, $claim->token, 'Attempt limit exceeded');

            return null;
        }

        return $claim;
    }

    /**
     * @throws ActionTypeMismatchException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function claim(string $submissionId, string $actionId): ?ActionClaimDTO
    {
        $actionType = $this->actionRepository->findActionType($actionId);

        if ($actionType !== null && $actionType !== ActionType::QuoteIssued) {
            throw new ActionTypeMismatchException($actionId, ActionType::QuoteIssued, $actionType);
        }

        $claim = $actionType === null ? null : $this->actionRepository->claim($actionId);
        if ($claim === null) {
            $this->logger->info('Quote conversion action missing or not claimable — skipping', [
                'submission_id' => $submissionId,
                'action_id' => $actionId,
                'action_found' => $actionType !== null,
            ]);
        }

        return $claim;
    }

    /**
     * @throws ExternalServiceUnavailableException When the ad platform/DB unavailable
     * @throws AuthenticationExpiredException When ad platform credentials invalid
     * @throws InvalidApiRequestException When the ad platform rejects the conversion
     * @throws InvalidApiResponseException When the ad platform API response is malformed
     * @throws UnsupportedConversionTypeException When the platform does not support quote conversions
     * @throws RecordNotFoundException When the submission no longer exists
     * @throws MalformedStoredDataException When stored submission JSONB or convertedAt is corrupted
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException When the click ID is missing
     * @throws InvalidFormatException When the stored click ID has an invalid format
     */
    private function upload(string $submissionId, QuoteConversionDetailsDTO $details, AdPlatform $platform): void
    {
        $submission = $this->submissionRepository->findById($submissionId);
        $adapter = $this->adapterResolver->adapterFor($platform);

        $adapter->upload(
            ConversionType::QuoteIssued,
            ConversionUploadTransformer::forQuote($adapter->extractClickId($submission->attribution), $submission, $details),
        );
    }

    /**
     * A transient failure returns the row to pending; any other fails it and is rethrown
     * unless the claim was lost.
     *
     * @throws ExternalServiceUnavailableException Carrying the retry delay, or when DB unavailable
     * @throws PermanentApiFailure
     * @throws AbstractDataException
     * @throws UnsupportedConversionTypeException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function handleUploadFailure(
        string $submissionId,
        string $actionId,
        ActionClaimDTO $claim,
        TransientApiFailure|PermanentApiFailure|AbstractDataException|UnsupportedConversionTypeException $e,
    ): void {
        if ($e instanceof TransientApiFailure) {
            $this->retryLater($submissionId, $actionId, $claim, $e);

            return;
        }

        if ($this->failureHandler->execute($submissionId, $actionId, $claim->token, $e->getMessage())) {
            throw $e;
        }
    }

    /**
     * The last attempt fails the row instead, since the claim's attempt count is the only retry budget.
     *
     * @throws ExternalServiceUnavailableException Carrying the delay the row was returned to pending with
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function retryLater(string $submissionId, string $actionId, ActionClaimDTO $claim, TransientApiFailure $e): void
    {
        $attempts = new ActionAttempts($claim->attempts);

        if ($attempts->isLast()) {
            $this->failureHandler->execute($submissionId, $actionId, $claim->token, 'Retries exhausted: ' . $e->getMessage());

            return;
        }

        $delay = $attempts->retryDelay($e->retryAfter);
        if (! $this->actionRepository->returnToPending($actionId, $claim->token, $delay)) {
            $this->logClaimLost($submissionId, $actionId);

            return;
        }

        throw new ExternalServiceUnavailableException($e->serviceName, $delay, $e);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException When DB unavailable
     */
    private function complete(string $submissionId, string $actionId, AdPlatform $platform, string $claimToken): void
    {
        if (! $this->actionRepository->completeClaim($actionId, $claimToken, self::COMPLETION_RECEIPT)) {
            $this->logClaimLost($submissionId, $actionId);

            return;
        }

        $this->logger->info('Quote conversion uploaded', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'platform' => $platform->value,
        ]);
    }

    private function logClaimLost(string $submissionId, string $actionId): void
    {
        $this->logger->warning('Quote conversion action claim lost — write skipped', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
        ]);
    }
}
