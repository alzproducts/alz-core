<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\Exceptions\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Snooze information for a conversation.
 *
 * This field is critical - it's dropped by the SDK during hydration,
 * which is why we use direct HTTP instead.
 */
final class SnoozeResponse extends Data
{
    public function __construct(
        public readonly ?int $snoozedBy,
        public readonly ?string $snoozedUntil,
        public readonly ?bool $unsnoozeOnCustomerReply,
    ) {}

    /**
     * Transform to Domain value object.
     *
     * Returns null if snoozedUntil is not set.
     *
     * @throws InvalidApiResponseException When snoozedUntil has invalid date format
     */
    public function toDomain(): ?ConversationSnooze
    {
        if ($this->snoozedUntil === null) {
            return null;
        }

        try {
            $snoozedUntil = new DateTimeImmutable($this->snoozedUntil);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                serviceName: 'HelpScout',
                message: "Invalid snoozedUntil date format: {$this->snoozedUntil}",
                previous: $e,
            );
        }

        return new ConversationSnooze(
            snoozedUntil: $snoozedUntil,
            snoozedByUserId: $this->snoozedBy,
        );
    }
}
