<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Fakes;

use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedTasksClient implements TasksClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function getTasksForList(ApiKeyToken $token, string $listId, ClickUpTaskQueryParams $params): array
    {
        throw $this->gate->failClosed(LiveIntegration::ClickUp);
    }

    public function updateStatus(ApiKeyToken $token, string $taskId, string $status): void
    {
        throw $this->gate->failClosed(LiveIntegration::ClickUp);
    }
}
