<?php

declare(strict_types=1);

namespace App\Application\ClickUp\DTOs;

final readonly class ClickUpUserDataDTO
{
    public function __construct(
        public string $id,
        public string $email,
    ) {}
}
