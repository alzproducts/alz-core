<?php

declare(strict_types=1);

namespace App\Application\ClickUp\Results;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Lightweight metadata about a stored API key — no plaintext value exposed.
 */
final readonly class UserApiKeyMetaResult
{
    public ?DateTimeImmutable $lastUsedAt;

    public function __construct(
        public bool $hasKey,
        public ?string $maskedKey,
        ?DateTimeInterface $lastUsedAt,
    ) {
        $this->lastUsedAt = $lastUsedAt !== null
            ? DateTimeImmutable::createFromInterface($lastUsedAt)
            : null;
    }
}
