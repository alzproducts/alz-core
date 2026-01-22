<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Resources;

use App\Domain\CustomerService\ValueObjects\Conversation;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for HelpScout conversations.
 *
 * Transforms Domain objects to match the HelpScout API contract expected by
 * alz-admin's Zod schemas. Key transformations:
 *
 * - `customer` → `primaryCustomer`
 * - `customerWaitingSince` + `customerWaitingFriendly` → `{time, friendly}` object
 * - All dates → ISO 8601 strings
 * - Null fields → omitted (not included as `null`)
 *
 * @mixin Conversation
 */
final class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return \array_filter([
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(DateTimeInterface::ATOM),
            'userUpdatedAt' => $this->userUpdatedAt?->format(DateTimeInterface::ATOM),
            'mailboxName' => $this->mailboxName,
            'primaryCustomer' => $this->customer !== null
                ? new CustomerResource($this->customer)
                : null,
            'assignee' => $this->assignee !== null
                ? new AssigneeResource($this->assignee)
                : null,
            'tags' => TagResource::collection($this->tags),
            'customerWaitingSince' => $this->buildCustomerWaitingSince(),
            'snooze' => $this->snooze !== null
                ? new SnoozeResource($this->snooze)
                : null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Build the customerWaitingSince object with time and friendly properties.
     *
     * @return array{time: string, friendly?: string}|null
     */
    private function buildCustomerWaitingSince(): ?array
    {
        if ($this->customerWaitingSince === null) {
            return null;
        }

        return \array_filter([
            'time' => $this->customerWaitingSince->format(DateTimeInterface::ATOM),
            'friendly' => $this->customerWaitingFriendly,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
