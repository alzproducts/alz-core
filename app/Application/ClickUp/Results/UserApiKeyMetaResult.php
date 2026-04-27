<?php

declare(strict_types=1);

namespace App\Application\ClickUp\Results;

use DateTimeImmutable;

/**
 * Lightweight metadata about a stored API key — no plaintext value exposed.
 */
final readonly class UserApiKeyMetaResult
{
    public function __construct(
        public bool $hasKey,
        public ?string $maskedKey,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}
}
