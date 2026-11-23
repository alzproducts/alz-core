<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Domain\Access\Enums\ThirdPartyService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Access\EloquentUserApiKeyRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EloquentUserApiKeyRepository::class)]
final class EloquentUserApiKeyRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'public.user_api_keys';

    private UserApiKeyRepositoryInterface $repository;

    private Guid $userId;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(UserApiKeyRepositoryInterface::class);
        $this->userId = new Guid((string) Str::uuid());
    }

    private function createAuthUser(): void
    {
        // intentional: the local signup trigger copies first_name into public.profiles, where it is NOT NULL.
        DB::table('auth.users')->insert([
            'id' => $this->userId->value,
            'email' => "{$this->userId->value}@example.test",
            'raw_user_meta_data' => \json_encode(['first_name' => 'Test', 'last_name' => 'User'], \JSON_THROW_ON_ERROR),
        ]);
    }

    #[Test]
    public function it_round_trips_a_key_encrypted_at_rest(): void
    {
        $this->createAuthUser();
        $this->repository->save($this->userId, ThirdPartyService::ClickUp, new ApiKeyToken('pk_round_trip_secret_123'));

        $stored = DB::table(self::TABLE)->where('user_id', $this->userId->value)->value('encrypted_key');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('pk_round_trip_secret_123', $stored);

        $token = $this->repository->tokenForUser($this->userId, ThirdPartyService::ClickUp);
        $this->assertSame('pk_round_trip_secret_123', $token?->value);

        $meta = $this->repository->metaForUser($this->userId, ThirdPartyService::ClickUp);
        $this->assertTrue($meta->hasKey);
        $this->assertSame('pk_r..._123', $meta->maskedKey);
    }

    #[Test]
    public function it_replaces_the_key_on_a_second_save_and_forgets_it_on_delete(): void
    {
        $this->createAuthUser();
        $this->repository->save($this->userId, ThirdPartyService::HelpScout, new ApiKeyToken('first-key-value'));
        $this->repository->save($this->userId, ThirdPartyService::HelpScout, new ApiKeyToken('second-key-value'));

        $this->assertSame(1, DB::table(self::TABLE)->where('user_id', $this->userId->value)->count());
        $this->assertSame(
            'second-key-value',
            $this->repository->tokenForUser($this->userId, ThirdPartyService::HelpScout)?->value,
        );

        $this->repository->delete($this->userId, ThirdPartyService::HelpScout);

        $this->assertNull($this->repository->tokenForUser($this->userId, ThirdPartyService::HelpScout));
        $this->assertFalse($this->repository->metaForUser($this->userId, ThirdPartyService::HelpScout)->hasKey);
    }
}
