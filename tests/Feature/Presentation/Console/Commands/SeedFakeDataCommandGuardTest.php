<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Presentation\Console\Commands\Dev\SeedFakeDataCommand;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The seed refuses anywhere but a local or testing app on a loopback database with the sale category in the
 * reserved slots, before any database call (AC7).
 */
#[CoversClass(SeedFakeDataCommand::class)]
final class SeedFakeDataCommandGuardTest extends TestCase
{
    private const string SEEDER = 'Database\\Seeders\\ProductSlice\\ProductSliceSeeder';

    private int $queries = 0;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection = \config()->string('database.default');
        \config([
            "database.connections.{$connection}.host" => '127.0.0.1',
            'shopwired.sale_category_id' => 2_000_000_001,
        ]);
        DB::listen(function (): void {
            ++$this->queries;
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackHosts(): iterable
    {
        yield 'IPv4' => ['127.0.0.1'];
        yield 'name' => ['localhost'];
        yield 'IPv6' => ['::1'];
    }

    #[Test]
    #[DataProvider('loopbackHosts')]
    public function it_allows_a_testing_app_on_a_loopback_host(string $host): void
    {
        $this->setHost($host);

        $this->assertNull(SeedFakeDataCommand::guardFailure());
    }

    #[Test]
    public function it_allows_the_local_environment(): void
    {
        $this->app->detectEnvironment(static fn(): string => 'local');

        $this->assertNull(SeedFakeDataCommand::guardFailure());
    }

    #[Test]
    public function it_refuses_a_production_environment_before_any_database_call(): void
    {
        $this->app->detectEnvironment(static fn(): string => 'production');

        $this->artisan('dev:seed-fake', ['--fresh' => true])
            ->expectsOutput('Refusing to seed: the environment is not local or testing.')
            ->assertFailed();
        $this->assertSame(0, $this->queries);
    }

    #[Test]
    public function it_refuses_a_non_loopback_database_host_before_any_database_call(): void
    {
        $this->setHost('db.example.supabase.co');

        $this->artisan('dev:seed-fake', ['--fresh' => true])
            ->expectsOutput('Refusing to seed: the default database connection host is not a loopback address.')
            ->assertFailed();
        $this->assertSame(0, $this->queries);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function saleCategoriesOutsideTheSlots(): iterable
    {
        yield 'unset' => [0];
        yield 'a real category ID' => [1_234_567];
        yield 'just below the slots' => [1_999_999_999];
        yield 'just above the slots' => [2_000_000_100];
    }

    #[Test]
    #[DataProvider('saleCategoriesOutsideTheSlots')]
    public function it_refuses_a_sale_category_outside_the_singleton_slots(int $saleCategoryId): void
    {
        \config(['shopwired.sale_category_id' => $saleCategoryId]);

        $this->artisan('dev:seed-fake', ['--fresh' => true])
            ->expectsOutput('Refusing to seed: the sale category ID is outside the seed\'s reserved slots 2000000000-2000000099.')
            ->assertFailed();
        $this->assertSame(0, $this->queries);
    }

    #[Test]
    public function it_accepts_both_ends_of_the_singleton_slots(): void
    {
        \config(['shopwired.sale_category_id' => 2_000_000_000]);
        $this->assertNull(SeedFakeDataCommand::guardFailure());

        \config(['shopwired.sale_category_id' => 2_000_000_099]);
        $this->assertNull(SeedFakeDataCommand::guardFailure());
    }

    #[Test]
    public function a_direct_seeder_run_in_production_refuses_before_any_database_call(): void
    {
        $this->app->detectEnvironment(static fn(): string => 'production');

        try {
            $this->artisan('db:seed', ['--class' => self::SEEDER, '--force' => true])->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertSame('Refusing to seed: the environment is not local or testing.', $e->getMessage());
        }

        $this->assertSame(0, $this->queries);
    }

    #[Test]
    public function it_rejects_a_non_numeric_seed(): void
    {
        $this->artisan('dev:seed-fake', ['--dry-run' => true, '--seed' => 'abc'])
            ->expectsOutput('The faker seed must be a non-negative integer.')
            ->assertFailed();
        $this->assertSame(0, $this->queries);
    }

    private function setHost(string $host): void
    {
        $connection = \config()->string('database.default');
        \config(["database.connections.{$connection}.host" => $host]);
    }
}
