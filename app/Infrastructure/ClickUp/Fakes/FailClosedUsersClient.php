<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Fakes;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Application\Contracts\ClickUp\UsersClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;

final readonly class FailClosedUsersClient implements UsersClientInterface
{
    public function __construct(private LiveIntegrationGate $gate) {}

    public function getUser(ApiKeyToken $token): ClickUpUserDataDTO
    {
        throw $this->gate->failClosed(LiveIntegration::ClickUp);
    }
}
