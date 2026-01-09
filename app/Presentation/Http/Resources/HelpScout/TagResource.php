<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources\HelpScout;

use App\Domain\CustomerService\ValueObjects\ConversationTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for conversation tags.
 *
 * Maps Domain property names to HelpScout API contract:
 * - `name` → `tag` (HelpScout uses 'tag' for the tag name)
 *
 * @mixin ConversationTag
 */
final class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->name,
            'color' => $this->color,
        ];
    }
}
