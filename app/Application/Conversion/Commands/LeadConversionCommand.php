<?php

declare(strict_types=1);

namespace App\Application\Conversion\Commands;

use App\Domain\ValueObjects\Uuid;

/**
 * Carries the resolved identifiers needed to dispatch lead conversion processing.
 *
 * The action ID is created synchronously before dispatch so the async pipeline
 * can update status without re-querying.
 */
final readonly class LeadConversionCommand
{
    public function __construct(
        public Uuid $submissionId,
        public Uuid $actionId,
    ) {}
}
