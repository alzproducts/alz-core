<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Config;

use App\Domain\ValueObjects\IntId;

/**
 * HelpScout system user ID for automated actions.
 *
 * Used for attribution of automated notes (e.g., email validation warnings).
 * Wraps IntId for type-safe dependency injection - allows the container to
 * auto-resolve this specific config value wherever it's type-hinted.
 *
 * Bound as singleton in HelpScoutServiceProvider with fail-fast validation.
 */
final readonly class HelpScoutSystemUserId
{
    public function __construct(
        public IntId $value,
    ) {}
}
