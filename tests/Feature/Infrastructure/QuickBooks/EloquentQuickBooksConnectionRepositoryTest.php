<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\Persistence\Models\QuickBooks\QuickBooksConnectionModel;
use App\Infrastructure\QuickBooks\EloquentQuickBooksConnectionRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs on the default connection inside a rolled-back transaction, so a developer's
 * connected sandbox row is deleted only for the test's lifetime (COR-280 D25).
 */
#[CoversClass(EloquentQuickBooksConnectionRepository::class)]
final class EloquentQuickBooksConnectionRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'public.quickbooks_connections';

    private QuickBooksConnectionRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table(self::TABLE)->where('environment', 'sandbox')->delete();
        $this->repository = $this->app->make(QuickBooksConnectionRepositoryInterface::class);
    }

    #[Test]
    public function it_round_trips_a_connection_with_the_refresh_token_encrypted_at_rest(): void
    {
        $connection = $this->connection('refresh-token-one', '2027-02-26T10:00:00+00:00', '2031-11-18T10:00:00+00:00');

        $this->repository->save($connection);

        $row = DB::table(self::TABLE)->where('environment', 'sandbox')->sole();
        $this->assertIsString($row->encrypted_refresh_token);
        $this->assertNotSame('refresh-token-one', $row->encrypted_refresh_token);
        $this->assertStringNotContainsString('refresh-token-one', $row->encrypted_refresh_token);
        $this->assertSame(
            'refresh-token-one',
            $this->app->make(ApiKeyCipherInterface::class)->decrypt($row->encrypted_refresh_token)->value,
        );

        $found = $this->repository->find();

        $this->assertNotNull($found);
        $this->assertSame('refresh-token-one', $found->refreshToken->value);
        $this->assertSame('2027-02-26T10:00:00+00:00', $found->refreshTokenExpiresAt->format(DATE_ATOM));
        $this->assertSame('2031-11-18T10:00:00+00:00', $found->refreshTokenHardExpiresAt?->format(DATE_ATOM));
    }

    #[Test]
    public function it_keeps_one_row_per_environment_when_saving_a_rotated_token(): void
    {
        $this->repository->save($this->connection('refresh-token-one', '2027-02-26T10:00:00+00:00', null));
        $this->repository->save($this->connection('refresh-token-two', '2027-03-05T10:00:00+00:00', null));

        $this->assertSame(1, DB::table(self::TABLE)->where('environment', 'sandbox')->count());

        $found = $this->repository->find();
        $this->assertNotNull($found);
        $this->assertSame('refresh-token-two', $found->refreshToken->value);
        $this->assertSame('2027-03-05T10:00:00+00:00', $found->refreshTokenExpiresAt->format(DATE_ATOM));
        $this->assertNull($found->refreshTokenHardExpiresAt);
    }

    #[Test]
    public function it_returns_null_when_no_connection_is_stored(): void
    {
        $this->assertNull($this->repository->find());
    }

    #[Test]
    public function it_deletes_the_connection_without_decrypting_it(): void
    {
        DB::table(self::TABLE)->insert([
            'environment' => 'sandbox',
            'encrypted_refresh_token' => 'not-a-ciphertext',
            'refresh_token_expires_at' => '2027-02-26 10:00:00+00',
        ]);

        $this->repository->delete();

        $this->assertSame(0, DB::table(self::TABLE)->where('environment', 'sandbox')->count());
    }

    #[Test]
    public function it_ignores_and_keeps_a_connection_stored_for_another_environment(): void
    {
        DB::table(self::TABLE)->where('environment', 'production')->delete();
        DB::table(self::TABLE)->insert([
            'environment' => 'production',
            'encrypted_refresh_token' => 'production-ciphertext',
            'refresh_token_expires_at' => '2027-02-26 10:00:00+00',
        ]);

        $this->assertNull($this->repository->find());

        $this->repository->delete();

        $this->assertSame(1, DB::table(self::TABLE)->where('environment', 'production')->count());
    }

    #[Test]
    public function the_model_uses_the_default_connection(): void
    {
        $this->assertNull((new QuickBooksConnectionModel())->getConnectionName());
    }

    #[Test]
    public function the_table_has_rls_on_with_no_policy_and_no_supabase_role_grants(): void
    {
        $rls = DB::selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE oid = 'public.quickbooks_connections'::regclass",
        );
        $policies = DB::selectOne(
            "SELECT count(*) AS total FROM pg_policy WHERE polrelid = 'public.quickbooks_connections'::regclass",
        );
        $grants = DB::select(
            "SELECT grantee FROM information_schema.role_table_grants
             WHERE table_schema = 'public' AND table_name = 'quickbooks_connections'
               AND grantee IN ('PUBLIC', 'anon', 'authenticated', 'service_role') AND grantee <> current_user",
        );

        $this->assertNotNull($rls);
        $this->assertTrue($rls->relrowsecurity);
        $this->assertNotNull($policies);
        $this->assertSame(0, $policies->total);
        $this->assertSame([], $grants);
    }

    private function connection(string $refreshToken, string $expiresAt, ?string $hardExpiresAt): QuickBooksConnection
    {
        return new QuickBooksConnection(
            refreshToken: new ApiKeyToken($refreshToken),
            refreshTokenExpiresAt: new DateTimeImmutable($expiresAt),
            refreshTokenHardExpiresAt: $hardExpiresAt === null ? null : new DateTimeImmutable($hardExpiresAt),
        );
    }
}
