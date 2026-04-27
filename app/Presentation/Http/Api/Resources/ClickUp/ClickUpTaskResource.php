<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin ClickUpTaskDataDTO
 */
final class ClickUpTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ClickUpTaskDataDTO $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status,
            'due_date' => $task->dueDate,
            'tags' => $task->tags,
            'url' => $task->url,
        ];
    }
}
