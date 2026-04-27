<?php

declare(strict_types=1);

namespace App\Application\ClickUp\DTOs;

use DateTimeImmutable;

final readonly class ClickUpApiKeyMetaDTO
{
    public function __construct(
        public bool $hasKey,
        public ?string $maskedKey,
        public ?DateTimeImmutable $lastUsedAt,
        public ?string $clickupUserEmail,
    ) {}
}
