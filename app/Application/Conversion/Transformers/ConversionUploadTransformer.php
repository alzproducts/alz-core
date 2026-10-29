<?php

declare(strict_types=1);

namespace App\Application\Conversion\Transformers;

use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\QuoteConversionDetailsDTO;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Builds the ad-platform upload payload for a lead or quote conversion of a contact submission.
 */
final readonly class ConversionUploadTransformer
{
    /**
     * @throws InsufficientDataException When the click ID or submission timestamp is missing
     */
    public static function forLead(?string $clickId, ContactSubmission $submission): ConversionUploadDTO
    {
        $clickId = self::requireClickId($clickId);

        $submittedAt = $submission->submittedAt;
        if ($submittedAt === null) {
            throw new InsufficientDataException('ContactSubmission', 'a submission timestamp for conversion time');
        }

        return new ConversionUploadDTO(
            clickId: $clickId,
            email: $submission->form->email,
            convertedAt: $submittedAt,
            value: null,
            phone: $submission->form->phone,
        );
    }

    /**
     * `convertedAt` is the staff-provided timestamp, not the submission's `submittedAt`:
     * a quote may be issued days after the form.
     *
     * @throws InsufficientDataException When the click ID is missing
     * @throws MalformedStoredDataException When the queued convertedAt is not a parseable date
     */
    public static function forQuote(?string $clickId, ContactSubmission $submission, QuoteConversionDetailsDTO $details): ConversionUploadDTO
    {
        return new ConversionUploadDTO(
            clickId: self::requireClickId($clickId),
            email: $submission->form->email,
            convertedAt: self::parseConvertedAt($details->convertedAt),
            value: Money::exclusive($details->value),
            phone: $submission->form->phone,
        );
    }

    /**
     * @throws InsufficientDataException
     */
    private static function requireClickId(?string $clickId): string
    {
        if ($clickId === null) {
            throw new InsufficientDataException('ContactSubmission', 'a click ID for the ad platform conversion upload');
        }

        return $clickId;
    }

    /**
     * The convertedAt string was produced by `QueuedConversionDispatcher` formatting
     * the command's `DateTimeImmutable` as ATOM. A parse failure here means the queue
     * payload was tampered or corrupted between dispatch and execution.
     *
     * @throws MalformedStoredDataException
     */
    private static function parseConvertedAt(string $convertedAt): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($convertedAt);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                'ConversionJobPayload',
                'converted_at must be a parseable date string',
                previous: $e,
            );
        }
    }
}
