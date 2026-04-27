<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\UseCases\CompleteClickUpTaskUseCase;
use App\Application\ClickUp\UseCases\GetMyClickUpTasksUseCase;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Exceptions\Api\MissingApiKeyException;
use App\Presentation\Http\Api\Controllers\ClickUp\ClickUpTaskController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ClickUpTaskController::class)]
final class ClickUpTaskControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private GetMyClickUpTasksUseCase&MockInterface $getTasksUseCase;

    private CompleteClickUpTaskUseCase&MockInterface $completeTaskUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTasksUseCase = Mockery::mock(GetMyClickUpTasksUseCase::class);
        $this->completeTaskUseCase = Mockery::mock(CompleteClickUpTaskUseCase::class);

        $this->app->instance(GetMyClickUpTasksUseCase::class, $this->getTasksUseCase);
        $this->app->instance(CompleteClickUpTaskUseCase::class, $this->completeTaskUseCase);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/clickup/tasks
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/clickup/tasks');

        $response->assertStatus(401);
    }

    #[Test]
    public function index_returns_list_of_tasks(): void
    {
        $tasks = [
            new ClickUpTaskDataDTO(
                id: 'task_abc',
                name: 'Fix login bug',
                status: 'in progress',
                dueDate: '1711929600000',
                tags: ['bug', 'urgent'],
                url: 'https://app.clickup.com/t/task_abc',
            ),
        ];

        $this->getTasksUseCase->shouldReceive('execute')->once()->andReturn($tasks);

        $response = $this->asApprovedUser()->getJson('/api/clickup/tasks');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'task_abc')
            ->assertJsonPath('data.0.name', 'Fix login bug')
            ->assertJsonPath('data.0.status', 'in progress')
            ->assertJsonPath('data.0.tags', ['bug', 'urgent']);
    }

    #[Test]
    public function index_returns_412_when_no_api_key_configured(): void
    {
        $this->getTasksUseCase->shouldReceive('execute')
            ->once()
            ->andThrow(new MissingApiKeyException(ThirdPartyService::ClickUp));

        $response = $this->asApprovedUser()->getJson('/api/clickup/tasks');

        $response->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed');
    }

    #[Test]
    public function index_passes_force_refresh_flag_to_use_case(): void
    {
        $this->getTasksUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(
                    static fn($params): bool => $params->forceRefresh === true,
                ),
            )
            ->andReturn([]);

        $response = $this->asApprovedUser()->getJson('/api/clickup/tasks?refresh=1');

        $response->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/clickup/tasks/{taskId}/complete
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function complete_marks_task_as_done(): void
    {
        $this->completeTaskUseCase->shouldReceive('execute')
            ->once()
            ->with(Mockery::any(), 'task_abc');

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/tasks/task_abc/complete');

        $response->assertNoContent();
    }

    #[Test]
    public function complete_returns_412_when_no_api_key_configured(): void
    {
        $this->completeTaskUseCase->shouldReceive('execute')
            ->once()
            ->andThrow(new MissingApiKeyException(ThirdPartyService::ClickUp));

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/tasks/task_abc/complete');

        $response->assertStatus(412);
    }
}
