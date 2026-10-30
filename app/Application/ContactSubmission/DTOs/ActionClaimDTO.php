<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\DTOs;

use DateTimeImmutable;

final readonly class ActionClaimDTO
{
    public function __construct(
        public string $token,
        public int $attempts,
        public ?string $externalId,
        public DateTimeImmutable $createdAt,
    ) {}
}
