<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Resources;

use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for conversation snooze state.
 *
 * Maps Domain property names to HelpScout API contract:
 * - `snoozedByUserId` → `snoozedBy`
 *
 * Formats dates as ISO 8601 strings.
 * Null fields are omitted from the response.
 *
 * @mixin ConversationSnooze
 */
final class SnoozeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return \array_filter([
            'snoozedBy' => $this->snoozedByUserId,
            'snoozedUntil' => $this->snoozedUntil->format(DateTimeInterface::ATOM),
            'unsnoozeOnCustomerReply' => $this->unsnoozeOnCustomerReply,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
