<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Enums;

use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SyncReasonCode::class)]
final class SyncReasonCodeTest extends TestCase
{
    #[Test]
    public function each_code_keeps_its_outcome_and_retryable_flag(): void
    {
        $expected = [
            'cancelled' => ['skipped', false],
            'order_not_found' => ['skipped', false],
            'previously_failed' => ['skipped', true],
            'mapping_missing' => ['failed', false],
            'item_missing' => ['failed', true],
            'zero_lines' => ['failed', false],
            'tax_rate_unsupported' => ['failed', false],
            'closed_period' => ['failed', false],
            'qbo_validation' => ['failed', false],
            'qbo_unavailable' => ['failed', true],
            'outcome_unknown' => ['failed', true],
            'in_progress' => ['not_attempted', true],
            'deadline_exceeded' => ['not_attempted', true],
            'before_floor_date' => ['failed', false],
        ];

        $actual = [];
        foreach (SyncReasonCode::cases() as $code) {
            $actual[$code->value] = [$code->outcome()->value, $code->retryable()];
        }

        self::assertSame($expected, $actual);
    }
}
