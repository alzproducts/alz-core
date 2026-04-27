<?php

declare(strict_types=1);

namespace App\Application\Contracts\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\Guid;

interface ClickUpTasksCacheInterface
{
    /**
     * @return list<ClickUpTaskDataDTO>|null
     *
     * @throws ExternalServiceUnavailableException
     */
    public function get(Guid $userId, ClickUpTaskQueryParams $params): ?array;

    /**
     * @param list<ClickUpTaskDataDTO> $tasks
     */
    public function put(Guid $userId, ClickUpTaskQueryParams $params, array $tasks): void;

    public function forget(Guid $userId): void;
}
