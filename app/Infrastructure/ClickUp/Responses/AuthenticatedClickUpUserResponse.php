<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Responses;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Infrastructure\Contracts\DtoConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ClickUp `GET /user` response — the `user` object inside the root.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class AuthenticatedClickUpUserResponse extends Data implements DtoConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly ?string $email,
    ) {}

    public function toDto(): ClickUpUserDataDTO
    {
        return new ClickUpUserDataDTO(
            id: (string) $this->id,
            email: $this->email ?? $this->username,
        );
    }
}
