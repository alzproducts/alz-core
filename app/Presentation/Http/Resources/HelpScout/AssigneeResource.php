<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources\HelpScout;

use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for conversation assignees.
 *
 * Null fields are omitted from the response.
 *
 * @mixin ConversationAssignee
 */
final class AssigneeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return \array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
