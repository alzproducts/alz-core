<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Domain\Exceptions\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
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

    /**
     * Transform to Domain value object.
     *
     * @throws InvalidApiResponseException When date format is invalid
     */
    public function toDomain(): DomainConversation
    {
        try {
            $createdAt = new DateTimeImmutable($this->createdAt);
            $customerWaitingSince = ($this->customerWaitingSince !== null)
                ? new DateTimeImmutable($this->customerWaitingSince->time)
                : null;
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                serviceName: 'HelpScout',
                message: "Invalid date format in conversation {$this->id}",
                previous: $e,
            );
        }

        return new DomainConversation(
            id: $this->id,
            number: $this->number,
            subject: $this->subject,
            status: $this->status,
            mailboxId: $this->mailboxId ?? 0,
            createdAt: $createdAt,
            customerWaitingSince: $customerWaitingSince,
            snooze: $this->snooze?->toDomain(),
            tags: \array_values(\array_map(static fn(Tag $t) => $t->toDomain(), $this->tags ?? [])),
            customer: $this->primaryCustomer?->toDomain(),
            assignee: $this->assignee?->toDomain(),
        );
    }
}
