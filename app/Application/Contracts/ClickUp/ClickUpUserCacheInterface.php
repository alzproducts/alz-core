<?php

declare(strict_types=1);

namespace App\Application\Contracts\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\Guid;

interface ClickUpUserCacheInterface
{
    /**
     * @throws ExternalServiceUnavailableException
     */
    public function get(Guid $userId): ?ClickUpUserDataDTO;

    public function put(Guid $userId, ClickUpUserDataDTO $data): void;

    public function forget(Guid $userId): void;
}
