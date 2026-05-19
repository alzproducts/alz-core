<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Resources;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for HelpScout agent profile.
 *
 * camelCase keys match the contract expected by ${FRONTEND_APP}'s settings page.
 *
 * @mixin SupportAgent
 */
final class AgentProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var SupportAgent $agent */
        $agent = $this->resource;

        return [
            'id' => $agent->id,
            'email' => $agent->email,
            'firstName' => $agent->firstName,
            'lastName' => $agent->lastName,
            'role' => $agent->role,
        ];
    }
}
