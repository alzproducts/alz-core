<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Responses;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Infrastructure\Contracts\DtoConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ClickUp task object from the `GET /list/{id}/task` response.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class TaskResponse extends Data implements DtoConvertibleInterface
{
    /**
     * @param array<int, array<string, string>> $tags Raw tag objects from ClickUp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $dueDate,
        public readonly ?string $url,
        public readonly array $tags = [],
        public readonly ?StatusSubResponse $status = null,
    ) {}

    public function toDto(): ClickUpTaskDataDTO
    {
        return new ClickUpTaskDataDTO(
            id: $this->id,
            name: $this->name,
            status: $this->status !== null ? $this->status->status : '',
            dueDate: $this->dueDate,
            tags: \array_values(\array_filter(\array_map(
                static fn(array $tag): string => \is_string($tag['name'] ?? null) ? $tag['name'] : '',
                $this->tags,
            ), static fn(string $name): bool => $name !== '')),
            url: $this->url,
        );
    }
}
