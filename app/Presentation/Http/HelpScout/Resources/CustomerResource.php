<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Resources;

use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for conversation customers.
 *
 * Maps Domain property names to HelpScout API contract:
 * - `firstName` → `first`
 * - `lastName` → `last`
 *
 * Null fields are omitted from the response.
 *
 * @mixin ConversationCustomer
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return \array_filter([
            'email' => $this->email,
            'first' => $this->firstName,
            'last' => $this->lastName,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
