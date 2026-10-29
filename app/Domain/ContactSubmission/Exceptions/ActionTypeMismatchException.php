<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Exceptions;

use App\Domain\ContactSubmission\Enums\ActionType;
use LogicException;

/**
 * Thrown when a process use case receives an action ID belonging to another pipeline.
 *
 * Extends LogicException because action routing is coded: a mismatch means a
 * dispatcher sent the action to the wrong job, not that the runtime broke.
 */
final class ActionTypeMismatchException extends LogicException
{
    public function __construct(
        public readonly string $actionId,
        public readonly ActionType $expected,
        public readonly ActionType $actual,
    ) {
        parent::__construct('Action type does not match the processing pipeline');
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'action_id' => $this->actionId,
            'expected' => $this->expected->value,
            'actual' => $this->actual->value,
        ];
    }
}
