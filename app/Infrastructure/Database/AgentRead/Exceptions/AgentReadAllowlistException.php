<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead\Exceptions;

use LogicException;

final class AgentReadAllowlistException extends LogicException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct("The Read allowlist breaks the agent-read rules:\n" . \implode("\n", $violations));
    }
}
