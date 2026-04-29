<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers\ClickUp;

use App\Application\ClickUp\DTOs\ClickUpApiKeyMetaDTO;
use App\Application\ClickUp\UseCases\DeleteClickUpApiKeyUseCase;
use App\Application\ClickUp\UseCases\GetClickUpApiKeyInfoUseCase;
use App\Application\ClickUp\UseCases\SaveClickUpApiKeyUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Presentation\Http\Api\Controllers\ClickUp\ClickUpAuthController;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ClickUpAuthController::class)]
final class ClickUpAuthControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private SaveClickUpApiKeyUseCase&MockInterface $saveUseCase;

    private GetClickUpApiKeyInfoUseCase&MockInterface $infoUseCase;

    private DeleteClickUpApiKeyUseCase&MockInterface $deleteUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->saveUseCase = Mockery::mock(SaveClickUpApiKeyUseCase::class);
        $this->infoUseCase = Mockery::mock(GetClickUpApiKeyInfoUseCase::class);
        $this->deleteUseCase = Mockery::mock(DeleteClickUpApiKeyUseCase::class);

        $this->app->instance(SaveClickUpApiKeyUseCase::class, $this->saveUseCase);
        $this->app->instance(GetClickUpApiKeyInfoUseCase::class, $this->infoUseCase);
        $this->app->instance(DeleteClickUpApiKeyUseCase::class, $this->deleteUseCase);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/clickup/api-key
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function unauthenticated_save_returns_401(): void
    {
        $response = $this->postJson('/api/clickup/api-key', ['api_key' => 'pk_test']);

        $response->assertStatus(401);
    }

    #[Test]
    public function save_validates_and_persists_api_key(): void
    {
        $this->saveUseCase->shouldReceive('execute')->once();

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/api-key', ['api_key' => 'pk_test_my_clickup_key']);

        $response->assertNoContent();
    }

    #[Test]
    public function save_with_dry_run_validates_without_writing(): void
    {
        $this->saveUseCase->shouldReceive('execute')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                true,
            );

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/api-key?dry_run=true', ['api_key' => 'pk_test_key']);

        $response->assertNoContent();
    }

    #[Test]
    public function save_returns_422_when_api_key_is_missing(): void
    {
        $this->saveUseCase->shouldNotReceive('execute');

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/api-key', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function save_returns_502_when_clickup_rejects_the_key(): void
    {
        $this->saveUseCase->shouldReceive('execute')
            ->once()
            ->andThrow(new AuthenticationExpiredException('ClickUp', 'Invalid API key'));

        $response = $this->asApprovedUser()
            ->postJson('/api/clickup/api-key', ['api_key' => 'invalid_key']);

        $response->assertStatus(502);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/clickup/api-key
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function info_returns_key_metadata(): void
    {
        $meta = new ClickUpApiKeyMetaDTO(
            hasKey: true,
            maskedKey: 'pk_t...y123',
            lastUsedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            clickupUserEmail: 'user@example.com',
        );

        $this->infoUseCase->shouldReceive('execute')->once()->andReturn($meta);

        $response = $this->asApprovedUser()->getJson('/api/clickup/api-key');

        $response->assertOk()
            ->assertJsonPath('data.has_key', true)
            ->assertJsonPath('data.masked_key', 'pk_t...y123')
            ->assertJsonPath('data.clickup_user_email', 'user@example.com');
    }

    #[Test]
    public function info_returns_no_key_when_none_configured(): void
    {
        $meta = new ClickUpApiKeyMetaDTO(
            hasKey: false,
            maskedKey: null,
            lastUsedAt: null,
            clickupUserEmail: null,
        );

        $this->infoUseCase->shouldReceive('execute')->once()->andReturn($meta);

        $response = $this->asApprovedUser()->getJson('/api/clickup/api-key');

        $response->assertOk()
            ->assertJsonPath('data.has_key', false)
            ->assertJsonPath('data.masked_key', null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/clickup/api-key
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function delete_removes_the_api_key(): void
    {
        $this->deleteUseCase->shouldReceive('execute')->once();

        $response = $this->asApprovedUser()->deleteJson('/api/clickup/api-key');

        $response->assertNoContent();
    }
}
