<?php

declare(strict_types=1);

namespace Tests\Feature\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\InterceptedOrderSyncRepository;
use Tests\TestCase;

#[CoversNothing]
final class SyncOrderChunkUseCaseTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string REALM = '9130000000000001';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private FakeQuickBooksOrderPosting $posting;

    private InterceptedOrderSyncRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('quickbooks.order_syncs')->delete();
        $this->posting = new FakeQuickBooksOrderPosting();
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting);
        $this->repository = new InterceptedOrderSyncRepository($this->app->make(OrderSyncRepositoryInterface::class));
        $this->app->instance(OrderSyncRepositoryInterface::class, $this->repository);
    }

    #[Test]
    public function one_lease_caps_a_later_callers_deadline(): void
    {
        $lease = null;
        $this->posting->duringLookup = static function () use (&$lease): void {
            $lease = DB::table('quickbooks.order_syncs')->where('reference', self::REFERENCE)->value('lease_expires_at');
        };

        $this->execute([self::REFERENCE], new DateTimeImmutable('+1 hour'));

        self::assertIsString($lease);
        $this->assertDeadline((new DateTimeImmutable($lease))->modify('-150 seconds'));
    }

    #[Test]
    public function the_earliest_lease_caps_a_later_callers_deadline(): void
    {
        $earliest = new DateTimeImmutable('+10 minutes');
        $this->repository->leases = [self::REFERENCE => new DateTimeImmutable('+12 minutes'), self::OTHER_REFERENCE => $earliest];

        $this->execute([self::REFERENCE, self::OTHER_REFERENCE], new DateTimeImmutable('+1 hour'));

        $this->assertDeadline($earliest->modify('-150 seconds'));
    }

    #[Test]
    public function a_callers_deadline_before_the_cap_is_kept(): void
    {
        $caller = new DateTimeImmutable('+25 seconds');

        $this->execute([self::REFERENCE], $caller);

        $this->assertDeadline($caller);
    }

    private function assertDeadline(DateTimeImmutable $expected): void
    {
        self::assertNotSame([], $this->posting->deadlines);
        foreach ($this->posting->deadlines as $deadline) {
            self::assertSame($expected->format('U.u'), $deadline->at->format('U.u'));
        }
    }

    /**
     * @param non-empty-list<int> $references
     */
    private function execute(array $references, DateTimeImmutable $deadline): void
    {
        foreach ($references as $reference) {
            $this->insertOrder($reference);
        }

        $this->app->make(SyncOrderChunkUseCase::class)->execute(
            new ApiKeyToken('test-access-token'),
            new OrderSyncChunkDTO(self::REALM, $references, false, OrderSyncTrigger::Manual, null),
            Deadline::at($deadline),
        );
    }
}
