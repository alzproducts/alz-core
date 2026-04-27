<?php

declare(strict_types=1);

namespace App\Application\ClickUp\DTOs;

final readonly class ClickUpTaskDataDTO
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $dueDate,
        public array $tags,
        public ?string $url,
    ) {}
}
