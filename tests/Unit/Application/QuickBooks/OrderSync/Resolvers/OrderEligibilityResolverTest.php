<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimRulesDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRowStatus;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderEligibilityResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderEligibilityResult;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderEligibilityResolver::class)]
#[CoversClass(OrderEligibilityResult::class)]
final class OrderEligibilityResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderSyncStateDTO|null, OrderLifecycleStatus|null, bool, SyncOutcome|null, SyncReasonCode|null}>
     */
    public static function cases(): iterable
    {
        $live = OrderLifecycleStatus::Processing;
        $cancelled = OrderLifecycleStatus::Cancelled;
        $synced = self::state(OrderSyncRowStatus::Synced);
        $failed = self::state(OrderSyncRowStatus::Failed);
        $pending = self::state(OrderSyncRowStatus::Pending);
        $leased = self::state(OrderSyncRowStatus::Processing, true);
        $lapsed = self::state(OrderSyncRowStatus::Processing);

        foreach ([false, true] as $force) {
            $suffix = $force ? ', with force' : ', without force';

            yield "synced, live order{$suffix}" => [$synced, $live, $force, SyncOutcome::AlreadySynced, null];
            yield "synced, cancelled order{$suffix}" => [$synced, $cancelled, $force, SyncOutcome::AlreadySynced, null];
            yield "synced, unknown reference{$suffix}" => [$synced, null, $force, SyncOutcome::AlreadySynced, null];
            yield "cancelled, no row{$suffix}" => [null, $cancelled, $force, SyncOutcome::Skipped, SyncReasonCode::Cancelled];
            yield "cancelled, failed row{$suffix}" => [$failed, $cancelled, $force, SyncOutcome::Skipped, SyncReasonCode::Cancelled];
            yield "cancelled, live lease{$suffix}" => [$leased, $cancelled, $force, SyncOutcome::Skipped, SyncReasonCode::Cancelled];
            yield "unknown, no row{$suffix}" => [null, null, $force, SyncOutcome::Skipped, SyncReasonCode::OrderNotFound];
            yield "unknown, failed row{$suffix}" => [$failed, null, $force, SyncOutcome::Skipped, SyncReasonCode::OrderNotFound];
            yield "live order, live lease{$suffix}" => [$leased, $live, $force, SyncOutcome::NotAttempted, SyncReasonCode::InProgress];
            yield "live order, no row{$suffix}" => [null, $live, $force, null, null];
            yield "live order, pending row{$suffix}" => [$pending, $live, $force, null, null];
            yield "live order, lapsed lease{$suffix}" => [$lapsed, $live, $force, null, null];
        }

        yield 'live order, failed row, without force' => [$failed, $live, false, SyncOutcome::Skipped, SyncReasonCode::PreviouslyFailed];
        yield 'live order, failed row, with force' => [$failed, $live, true, null, null];
    }

    #[Test]
    #[DataProvider('cases')]
    public function the_first_matching_rule_decides(
        ?OrderSyncStateDTO $state,
        ?OrderLifecycleStatus $orderStatus,
        bool $forceRetry,
        ?SyncOutcome $outcome,
        ?SyncReasonCode $reason,
    ): void {
        $result = OrderEligibilityResolver::resolve($state, $orderStatus, new OrderSyncClaimRulesDTO($forceRetry, false, false));

        self::assertSame($outcome, $result->outcome);
        self::assertSame($reason, $result->reason);
        self::assertSame($outcome === null, $result->isClaimable());
    }

    /**
     * @return iterable<string, array{SyncReasonCode|null, bool, SyncReasonCode|null}>
     */
    public static function failedRowRetries(): iterable
    {
        yield 'item missing, retried by a scheduled claim' => [SyncReasonCode::ItemMissing, true, null];
        yield 'item missing, refused to another claim' => [SyncReasonCode::ItemMissing, false, SyncReasonCode::PreviouslyFailed];
        yield 'validation failure, refused to a scheduled claim' => [SyncReasonCode::QboValidation, true, SyncReasonCode::PreviouslyFailed];
        yield 'no reason, refused to a scheduled claim' => [null, true, SyncReasonCode::PreviouslyFailed];
    }

    #[Test]
    #[DataProvider('failedRowRetries')]
    public function an_unforced_claim_retries_a_failed_row_only_for_a_missing_item_when_the_rules_allow_it(
        ?SyncReasonCode $lastErrorCode,
        bool $retryItemMissing,
        ?SyncReasonCode $reason,
    ): void {
        $state = self::state(OrderSyncRowStatus::Failed, lastErrorCode: $lastErrorCode);

        $result = OrderEligibilityResolver::resolve($state, OrderLifecycleStatus::Processing, new OrderSyncClaimRulesDTO(false, false, $retryItemMissing));

        self::assertSame($reason, $result->reason);
        self::assertSame($reason === null, $result->isClaimable());
    }

    private static function state(OrderSyncRowStatus $status, bool $leaseLive = false, ?SyncReasonCode $lastErrorCode = null): OrderSyncStateDTO
    {
        return new OrderSyncStateDTO($status, $leaseLive, null, null, null, $lastErrorCode);
    }
}
