<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Linnworks\Enums;

use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PurchaseOrderStatus Enum Unit Tests.
 *
 * Tests the forward-only lifecycle state machine:
 * PENDING → OPEN → PARTIAL → DELIVERED.
 */
#[CoversClass(PurchaseOrderStatus::class)]
final class PurchaseOrderStatusTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Backing Values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function backing_values_match_expected_strings(): void
    {
        $this->assertSame('PENDING', PurchaseOrderStatus::Pending->value);
        $this->assertSame('OPEN', PurchaseOrderStatus::Open->value);
        $this->assertSame('PARTIAL', PurchaseOrderStatus::Partial->value);
        $this->assertSame('DELIVERED', PurchaseOrderStatus::Delivered->value);
    }

    /*
    |--------------------------------------------------------------------------
    | allowedTransitions()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function pending_allows_only_open(): void
    {
        $this->assertSame([PurchaseOrderStatus::Open], PurchaseOrderStatus::Pending->allowedTransitions());
    }

    #[Test]
    public function open_allows_partial_and_delivered(): void
    {
        $this->assertSame(
            [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Delivered],
            PurchaseOrderStatus::Open->allowedTransitions(),
        );
    }

    #[Test]
    public function partial_allows_only_delivered(): void
    {
        $this->assertSame([PurchaseOrderStatus::Delivered], PurchaseOrderStatus::Partial->allowedTransitions());
    }

    #[Test]
    public function delivered_allows_no_transitions(): void
    {
        $this->assertSame([], PurchaseOrderStatus::Delivered->allowedTransitions());
    }

    /*
    |--------------------------------------------------------------------------
    | canTransitionTo() — Allowed Transitions
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('allowedTransitionProvider')]
    public function can_transition_to_returns_true_for_allowed_transitions(
        PurchaseOrderStatus $from,
        PurchaseOrderStatus $to,
    ): void {
        $this->assertTrue($from->canTransitionTo($to));
    }

    /**
     * @return array<string, array{PurchaseOrderStatus, PurchaseOrderStatus}>
     */
    public static function allowedTransitionProvider(): array
    {
        return [
            'PENDING→OPEN' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Open],
            'OPEN→PARTIAL' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial],
            'OPEN→DELIVERED' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Delivered],
            'PARTIAL→DELIVERED' => [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Delivered],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | canTransitionTo() — Disallowed Transitions
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('disallowedTransitionProvider')]
    public function can_transition_to_returns_false_for_disallowed_transitions(
        PurchaseOrderStatus $from,
        PurchaseOrderStatus $to,
    ): void {
        $this->assertFalse($from->canTransitionTo($to));
    }

    /**
     * @return array<string, array{PurchaseOrderStatus, PurchaseOrderStatus}>
     */
    public static function disallowedTransitionProvider(): array
    {
        return [
            'PENDING→PENDING (self)' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Pending],
            'PENDING→PARTIAL (skip)' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Partial],
            'PENDING→DELIVERED (skip)' => [PurchaseOrderStatus::Pending, PurchaseOrderStatus::Delivered],
            'OPEN→OPEN (self)' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Open],
            'OPEN→PENDING (backwards)' => [PurchaseOrderStatus::Open, PurchaseOrderStatus::Pending],
            'PARTIAL→PARTIAL (self)' => [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Partial],
            'PARTIAL→PENDING (backwards)' => [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Pending],
            'PARTIAL→OPEN (backwards)' => [PurchaseOrderStatus::Partial, PurchaseOrderStatus::Open],
            'DELIVERED→PENDING' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Pending],
            'DELIVERED→OPEN' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Open],
            'DELIVERED→PARTIAL' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Partial],
            'DELIVERED→DELIVERED (self)' => [PurchaseOrderStatus::Delivered, PurchaseOrderStatus::Delivered],
        ];
    }
}
