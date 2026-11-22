<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncErrorRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncErrorDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Infrastructure\QuickBooks\EloquentOrderSyncErrorRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(EloquentOrderSyncErrorRepository::class)]
final class EloquentOrderSyncErrorRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_contract_exposes_only_an_append_method(): void
    {
        $methods = \array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(OrderSyncErrorRepositoryInterface::class))->getMethods(),
        );

        self::assertSame(['append'], $methods);
    }

    #[Test]
    public function each_append_adds_one_row_and_keeps_earlier_ones(): void
    {
        $syncId = DB::table('quickbooks.order_syncs')->insertGetId([
            'realm_id' => 'realm-error-test',
            'reference' => 2147450401,
            'order_external_id' => 2147450401,
        ]);
        self::assertIsString($syncId);
        $repository = $this->app->make(OrderSyncErrorRepositoryInterface::class);

        $repository->append($syncId, new OrderSyncErrorDTO(OrderSyncTrigger::Range, 'run-1', 'qbo_fault', '6000', 'Line.Amount', 'Amount is invalid'));
        $repository->append($syncId, new OrderSyncErrorDTO(OrderSyncTrigger::Manual, null, 'zero_lines'));

        $rows = DB::table('quickbooks.order_sync_errors')->where('order_sync_id', $syncId)->orderBy('id')->get()->all();
        self::assertCount(2, $rows);
        self::assertSame(
            ['range', 'run-1', 'qbo_fault', '6000', 'Line.Amount', 'Amount is invalid'],
            [$rows[0]->trigger, $rows[0]->run_id, $rows[0]->reason_code, $rows[0]->qbo_fault_code, $rows[0]->qbo_fault_element, $rows[0]->message],
        );
        self::assertSame(
            ['manual', null, 'zero_lines', null, null, null],
            [$rows[1]->trigger, $rows[1]->run_id, $rows[1]->reason_code, $rows[1]->qbo_fault_code, $rows[1]->qbo_fault_element, $rows[1]->message],
        );
        self::assertNotNull($rows[1]->created_at);
    }
}
