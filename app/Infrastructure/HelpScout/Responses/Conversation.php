<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * HelpScout conversation from API response.
 *
 * Parsed from HelpScout API JSON. Uses camelCase property names
 * to match the API response directly.
 */
final class Conversation extends Data
{
    /**
     * @param array<Tag>|null $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly int $number,
        public readonly string $subject,
        public readonly string $status,
        public readonly string $type,
        public readonly ?int $mailboxId,
        public readonly ?int $folderId,
        public readonly string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $closedAt,
        public readonly ?Customer $primaryCustomer,
        public readonly ?Assignee $assignee,
        public readonly ?CustomerWaitingSince $customerWaitingSince,
        public readonly ?Snooze $snooze,
        #[DataCollectionOf(Tag::class)]
        public readonly ?array $tags,
        public readonly ?string $preview,
        public readonly ?int $threads,
    ) {}

}
