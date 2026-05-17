<?php

declare(strict_types=1);

namespace App\Application\Conversion\Commands;

/**
 * Carries the resolved identifiers needed to dispatch lead conversion processing.
 *
 * The action ID is created synchronously before dispatch so the async pipeline
 * can update status without re-querying.
 */
final readonly class LeadConversionCommand
{
    public function __construct(
        public string $submissionId,
        public string $actionId,
    ) {}
}
