<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * HelpScout mailbox information.
 */
final class Mailbox extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $slug,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}
}
