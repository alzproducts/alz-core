<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\PotentialConversionSource;

/**
 * Read-model of a potential-conversion row's workflow stage — the slice the annotation /
 * dismiss / no-quote use cases need for existence checks and stage gating, projected from the
 * unified dashboard view for either source (form submission or call).
 */
final readonly class PotentialConversionStage
{
    public function __construct(
        public PotentialConversionSource $source,
        public ?ActionStatus $leadStatus,
        public ?ActionStatus $quoteStatus,
    ) {}

    public function isForm(): bool
    {
        return $this->source === PotentialConversionSource::Form;
    }

    public function hasLeadAction(): bool
    {
        return $this->leadStatus !== null;
    }
}
