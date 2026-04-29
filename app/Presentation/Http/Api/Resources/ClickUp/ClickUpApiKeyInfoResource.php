<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpApiKeyMetaDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin ClickUpApiKeyMetaDTO
 */
final class ClickUpApiKeyInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ClickUpApiKeyMetaDTO $meta */
        $meta = $this->resource;

        return [
            'has_key' => $meta->hasKey,
            'masked_key' => $meta->maskedKey,
            'last_used_at' => $meta->lastUsedAt?->format(DateTimeInterface::ATOM),
            'clickup_user_email' => $meta->clickupUserEmail,
        ];
    }
}
