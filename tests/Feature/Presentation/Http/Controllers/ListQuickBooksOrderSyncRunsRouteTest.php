<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversNothing]
final class ListQuickBooksOrderSyncRunsRouteTest extends TestCase
{
    use DatabaseTransactions;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/orders/runs';

    private const string RANGE_RUN = '0f8b6d2e-3c1a-4e5b-9a7d-2b6c4e8f1a90';

    private const string MANUAL_RUN = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

    private const string OLDEST_RUN = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('quickbooks.order_sync_runs')->delete();
    }

    #[Test]
    public function staff_get_runs_newest_requested_first_with_exactly_the_contract_fields(): void
    {
        $this->insertRun(self::OLDEST_RUN, ['requested_at' => '2026-11-20 09:00:00+00']);
        $this->insertRun(self::RANGE_RUN, [
            'force_retry' => true,
            'status' => 'completed',
            'candidate_count' => 5,
            'synced' => 1,
            'already_synced' => 2,
            'skipped' => 3,
            'failed' => 4,
            'not_attempted' => 5,
            'requested_at' => '2026-11-22 10:00:00+00',
            'started_at' => '2026-11-22 10:00:05+00',
            'finished_at' => '2026-11-22 10:03:00+00',
        ]);
        $this->insertRun(self::MANUAL_RUN, [
            'trigger' => 'manual',
            'range_from' => null,
            'range_to' => null,
            'order_references' => '{890000001,890000002}',
            'candidate_count' => 2,
            'requested_at' => '2026-11-23 10:00:00+00',
        ]);

        $response = $this->asRole('standard')->getJson(self::URL)->assertOk();

        self::assertSame([self::MANUAL_RUN, self::RANGE_RUN, self::OLDEST_RUN], \array_column($response->json('data'), 'id'));
        self::assertSame([
            'id' => self::MANUAL_RUN,
            'trigger' => 'manual',
            'force_retry' => false,
            'range' => null,
            'references' => [890000001, 890000002],
            'status' => 'queued',
            'candidate_count' => 2,
            'counts' => ['synced' => 0, 'already_synced' => 0, 'skipped' => 0, 'failed' => 0, 'not_attempted' => 0],
            'requested_at' => '2026-11-23T10:00:00+00:00',
            'started_at' => null,
            'finished_at' => null,
        ], $response->json('data.0'));
        self::assertSame([
            'id' => self::RANGE_RUN,
            'trigger' => 'range',
            'force_retry' => true,
            'range' => ['from' => '2026-11-01', 'to' => '2026-11-02'],
            'references' => null,
            'status' => 'completed',
            'candidate_count' => 5,
            'counts' => ['synced' => 1, 'already_synced' => 2, 'skipped' => 3, 'failed' => 4, 'not_attempted' => 5],
            'requested_at' => '2026-11-22T10:00:00+00:00',
            'started_at' => '2026-11-22T10:00:05+00:00',
            'finished_at' => '2026-11-22T10:03:00+00:00',
        ], $response->json('data.1'));
        $response->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 50);
    }

    #[Test]
    public function runs_requested_within_the_same_second_list_newest_first(): void
    {
        $repository = $this->app->make(OrderSyncRunRepositoryInterface::class);
        $repository->record($this->requested(self::OLDEST_RUN, '2026-11-22T10:00:00.100000+00:00'));
        $repository->record($this->requested(self::RANGE_RUN, '2026-11-22T10:00:00.900000+00:00'));

        $response = $this->asRole('standard')->getJson(self::URL)->assertOk();

        self::assertSame([self::RANGE_RUN, self::OLDEST_RUN], \array_column($response->json('data'), 'id'));
    }

    #[Test]
    public function pages_carry_the_query_parameters(): void
    {
        $this->insertRun(self::OLDEST_RUN, ['requested_at' => '2026-11-20 09:00:00+00']);
        $this->insertRun(self::RANGE_RUN, ['requested_at' => '2026-11-22 10:00:00+00']);

        $response = $this->asRole('standard')->getJson(self::URL . '?per_page=1&page=2');

        $response->assertOk()
            ->assertJsonPath('data.0.id', self::OLDEST_RUN)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
        $previous = $response->json('links.prev');
        self::assertIsString($previous);
        self::assertStringContainsString('per_page=1', $previous);
    }

    #[Test]
    public function an_invalid_page_size_is_refused(): void
    {
        $this->asRole('standard')->getJson(self::URL . '?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page'], 'error.errors');
    }

    #[Test]
    public function users_below_staff_are_refused(): void
    {
        $this->asRole('guest')->getJson(self::URL)->assertForbidden();
    }

    private function requested(string $id, string $requestedAt): RequestedOrderSyncRunDTO
    {
        return new RequestedOrderSyncRunDTO(
            $id,
            OrderSyncTrigger::Range,
            false,
            new DateRange(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-02')),
            null,
            1,
            new DateTimeImmutable($requestedAt),
        );
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insertRun(string $id, array $columns = []): void
    {
        DB::table('quickbooks.order_sync_runs')->insert([
            'id' => $id,
            'trigger' => 'range',
            'force_retry' => false,
            'range_from' => '2026-11-01',
            'range_to' => '2026-11-02',
            'status' => 'queued',
            'candidate_count' => 1,
            'requested_at' => '2026-11-21 10:00:00+00',
            ...$columns,
        ]);
    }
}
