<?php

declare(strict_types=1);

namespace App\Application\Conversion\Commands;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Uuid;
use DateTimeImmutable;

/**
 * Carries the resolved identifiers and quote-specific data needed to dispatch
 * quote conversion processing.
 *
 * The action ID is created synchronously before dispatch so the async pipeline
 * can update status without re-querying. `value` is GBP ex-VAT and `convertedAt`
 * is the staff-provided date — neither is derived from the submission.
 */
final readonly class QuoteConversionCommand
{
    public function __construct(
        public Uuid $submissionId,
        public Uuid $actionId,
        public Money $value,
        public DateTimeImmutable $convertedAt,
    ) {}
}
