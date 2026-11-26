<?php

declare(strict_types=1);

namespace Tests\Integration\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\QuickBooksCall;
use App\Infrastructure\QuickBooks\QuickBooksCallSpec;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use QuickBooksOnline\API\Data\IPPItem;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\TestCase;

/**
 * Measures the chunk use case at its largest chunk. The live run posts 100 documents and 100 customers to the sandbox.
 *
 * Seeds order rows only, in the developer's database with no transaction: the connection, mappings and cached token
 * there are the real sandbox ones, and a rollback would lose a rotated refresh token.
 */
#[CoversNothing]
#[Group('integration')]
#[Group('quickbooks-sandbox')]
final class QuickBooksSandboxOrderSyncTest extends TestCase
{
    use InsertsSourceOrders;

    private const int CHUNKS = 5;

    private const int REFERENCES = 20;

    // intentional: the run's references sit above ShopWired's, inside the integer column
    private const int FIRST_REFERENCE = 2_000_000_000;

    private const int RUNS = 1_000_000;

    /** @var list<int> */
    private array $references = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (\getenv('QUICKBOOKS_SANDBOX_RUN') !== '1') {
            self::markTestSkipped('QuickBooks sandbox tests run only through make test-quickbooks-sandbox');
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->deleteOrders();
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function the_measurement_syncs_every_order_against_a_faked_quickbooks(): void
    {
        $posting = $this->fakeQuickBooks();

        $measured = $this->measure('SKU-10');
        $references = $this->references;
        $this->deleteOrders();

        self::assertCount(self::CHUNKS, $measured['seconds']);
        self::assertSame(['synced' => self::CHUNKS * self::REFERENCES], $measured['outcomes']);
        self::assertSame(\array_map(static fn(int $reference): string => "A{$reference}", $references), $posting->lookedUp);
        self::assertCount(self::CHUNKS * self::REFERENCES, \array_unique(\array_map(
            static fn(CustomerDraftDTO $customer): string => $customer->displayName,
            $posting->customers,
        )));
        self::assertSame([0, 0, 0, 0], self::seededRows($references));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_sandbox_syncs_five_chunks_of_twenty_new_customers(): void
    {
        if (\getenv('QUICKBOOKS_SANDBOX_MEASURE') !== '1') {
            self::markTestSkipped('The live measurement runs only with QUICKBOOKS_SANDBOX_MEASURE=1');
        }
        $sku = $this->prepareSandbox();

        $measured = $this->measure($sku);

        \fwrite(\STDERR, "\n[order sync] " . \json_encode([
            ...$measured,
            'slowest' => \max($measured['seconds']),
            'deadline_exceeded' => $measured['outcomes']['deadline_exceeded'] ?? 0,
        ], \JSON_THROW_ON_ERROR) . "\n");
        self::assertSame(
            [],
            \array_diff(\array_keys($measured['outcomes']), ['synced', 'deadline_exceeded']),
            'An order ended neither synced nor deadline_exceeded',
        );
        self::assertSame(self::CHUNKS * self::REFERENCES, \array_sum($measured['outcomes']));
    }

    /**
     * Seeds one order per new customer, then syncs the chunks one after another.
     *
     * @return array{seconds: list<float>, outcomes: array<string, int>}
     */
    private function measure(string $sku): array
    {
        $this->seedOrders($sku);
        $realmId = $this->app->make(QuickBooksRealmInterface::class)->realmId();
        $seconds = [];
        $outcomes = [];
        foreach (\array_chunk($this->references, self::REFERENCES) as $references) {
            $chunk = new OrderSyncChunkDTO($realmId, $references, false, OrderSyncTrigger::Manual, null);
            $accessToken = $this->app->make(QuickBooksTokenService::class)->accessToken();
            $started = \hrtime(true);
            $results = $this->app->make(SyncOrderChunkUseCase::class)->execute(
                $accessToken,
                $chunk,
                Deadline::after(CarbonImmutable::now(), SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS),
            )->results;
            $seconds[] = \round((\hrtime(true) - $started) / 1e9, 2);

            self::assertCount(self::REFERENCES, $results);
            foreach ($results as $result) {
                $outcomes[] = $result->reason->value ?? $result->outcome->value;
            }
        }

        return ['seconds' => $seconds, 'outcomes' => \array_count_values($outcomes)];
    }

    private function seedOrders(string $sku): void
    {
        $first = self::FIRST_REFERENCE + \random_int(0, self::RUNS - 1) * self::CHUNKS * self::REFERENCES;
        $references = \range($first, $first + self::CHUNKS * self::REFERENCES - 1);
        // intentional: an existing row must never reach the cleanup's references
        self::assertSame([0, 0, 0, 0], self::seededRows($references), 'The chosen references are taken; run again');

        $this->references = $references;
        $placedAt = CarbonImmutable::now()->toDateTimeString() . '+00';
        foreach ($references as $reference) {
            $this->insertOrder($reference, $placedAt, [
                'billing_name' => "Sync Probe {$reference}",
                'billing_email' => "sync-probe-{$reference}@example.test",
            ], $sku);
        }
    }

    private function deleteOrders(): void
    {
        if ($this->references === []) {
            return;
        }

        DB::table('quickbooks.order_syncs')->whereIn('reference', $this->references)->delete();
        DB::table('shopwired.order_products')->whereIn('order_external_id', $this->references)->delete();
        DB::table('shopwired.orders')->whereIn('external_id', $this->references)->delete();
        $this->references = [];
    }

    /**
     * @param list<int> $references
     *
     * @return list<int> orders, lines, order syncs, order sync errors
     */
    private static function seededRows(array $references): array
    {
        $syncs = DB::table('quickbooks.order_syncs')->whereIn('reference', $references);

        return [
            DB::table('shopwired.orders')->whereIn('external_id', $references)->orWhereIn('reference', $references)->count(),
            DB::table('shopwired.order_products')->whereIn('order_external_id', $references)->count(),
            $syncs->count(),
            DB::table('quickbooks.order_sync_errors')->whereIn('order_sync_id', $syncs->select('id'))->count(),
        ];
    }

    /**
     * Doubles every contract that reaches QuickBooks or the stored sandbox setup; a write to one fails the test.
     */
    private function fakeQuickBooks(): FakeQuickBooksOrderPosting
    {
        \config([
            'quickbooks.environment' => 'sandbox',
            'quickbooks.client_id' => 'dry-client',
            'quickbooks.client_secret' => 'dry-secret',
            'quickbooks.redirect_uri' => 'http://localhost/dry',
            'quickbooks.realm_id' => '9130000000000001',
        ]);
        $posting = new FakeQuickBooksOrderPosting();
        $this->app->instance(QuickBooksOrderPostingInterface::class, $posting);
        $this->mock(QuickBooksRealmInterface::class)->shouldReceive('realmId')->andReturn('9130000000000001');
        $this->mock(QuickBooksAccessTokenCacheInterface::class)->shouldReceive('get')->andReturn(new ApiKeyToken('dry-access-token'));
        $this->mock(QuickBooksConnectionRepositoryInterface::class)->shouldReceive('find')->andReturn(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('dry-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
        $this->fakeSetup();

        return $posting;
    }

    private function fakeSetup(): void
    {
        $refs = \array_fill_keys(\array_map(static fn(MappingKey $key): string => $key->value, MappingKey::cases()), 'ref-1');
        $mappings = $this->mock(QuickBooksMappingRepositoryInterface::class);
        $mappings->shouldReceive('unresolvedKeys')->andReturn([]);
        $mappings->shouldReceive('resolvedRefs')->andReturn(new ResolvedMappingRefsDTO($refs));

        $settings = $this->mock(OrderSyncSettingsInterface::class);
        $settings->shouldReceive('backfillFloor')->andReturn(CarbonImmutable::yesterday());
        $settings->shouldReceive('runLabels')->andReturn(new DocumentRunLabelsDTO('', ''));
    }

    /**
     * Stops before the first chunk unless the sandbox can post.
     *
     * @return non-empty-string the name of an item the sandbox holds
     */
    private function prepareSandbox(): string
    {
        // intentional: checked before any QuickBooks service is built, so a production realm is never called
        if (\config('quickbooks.environment') !== 'sandbox') {
            self::fail('Refusing to run: QUICKBOOKS_ENVIRONMENT is not sandbox');
        }
        if ($this->app->make(QuickBooksConnectionRepositoryInterface::class)->find() === null) {
            self::fail('Missing: a sandbox connection (php artisan quickbooks:connect)');
        }
        $this->assertSandboxMapped();
        // intentional: the OAuth token refresh goes through the Http client
        Http::allowStrayRequests();
        \config(['quickbooks.backfill_floor_date' => CarbonImmutable::yesterday()->toDateString()]);

        return $this->sandboxItemName();
    }

    private function assertSandboxMapped(): void
    {
        $realmId = $this->app->make(QuickBooksRealmInterface::class)->realmId();
        $mappings = $this->app->make(QuickBooksMappingRepositoryInterface::class);
        $missing = \array_map(static fn(MappingKey $key): string => $key->value, $mappings->unresolvedKeys($realmId));
        $refs = $mappings->resolvedRefs($realmId);
        if ($refs->poNumberField() === null) {
            $missing[] = MappingKey::PoNumberField->value;
        }
        if ($refs->transactionIdField() === null) {
            $missing[] = MappingKey::TransactionIdField->value;
        }

        self::assertSame([], \array_values(\array_unique($missing)), 'Missing: resolved mapping keys');
    }

    /**
     * @return non-empty-string
     */
    private function sandboxItemName(): string
    {
        $rows = $this->app->make(QuickBooksTransport::class)->query(
            $this->app->make(QuickBooksTokenService::class)->accessToken(),
            "SELECT * FROM Item WHERE Type IN ('Service', 'NonInventory') AND Active = true MAXRESULTS 1",
            new QuickBooksCallSpec(QuickBooksCall::Other, Deadline::after(CarbonImmutable::now(), 60)),
        );
        $name = isset($rows[0]) && $rows[0] instanceof IPPItem ? (string) $rows[0]->Name : '';

        return $name !== '' ? $name : self::fail('Missing: an active sandbox item');
    }
}
