<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Exceptions;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\DomainException;

/**
 * The potential conversion is not in the workflow stage required for the requested action.
 *
 * Example: attempting to dismiss a row that already has a completed `lead_received`
 * action, or marking no-quote-expected when the lead hasn't completed yet.
 */
final class InvalidActionStageException extends DomainException
{
    public function __construct(
        public readonly string $sourceId,
        public readonly ActionType $action,
        public readonly ?ActionStatus $currentStatus,
    ) {
        parent::__construct('Potential conversion is not in the expected workflow stage for this action.');
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'source_id' => $this->sourceId,
            'action' => $this->action->value,
            'current_status' => $this->currentStatus?->value,
        ];
    }
}
