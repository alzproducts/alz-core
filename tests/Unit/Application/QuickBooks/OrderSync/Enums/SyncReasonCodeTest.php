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
    public function the_codes_are_the_contracts_rows_for_the_sync(): void
    {
        $codes = [];
        foreach (SyncReasonCode::cases() as $code) {
            $codes[$code->value] = [$code->outcome()->value, $code->retryable() ? 'true' : 'false'];
        }
        $documented = self::documentedCodes();
        \ksort($codes);
        \ksort($documented);

        self::assertSame($codes, $documented);
    }

    /**
     * @return array<string, array{string, string}> the outcome and `retryable` of each reason-code row `POST sync` returns
     */
    private static function documentedCodes(): array
    {
        $contract = (string) \file_get_contents(\base_path('docs/api-contracts/quickbooks-order-sync.md'));
        $found = \preg_match_all('/^\| `(\w+)` \| `(\w+)` \| `(true|false)` \|[^|]*`POST sync`[^|]*\|/m', $contract, $rows, \PREG_SET_ORDER);
        self::assertGreaterThan(0, $found);

        $documented = [];
        foreach ($rows as [, $outcome, $code, $retryable]) {
            self::assertArrayNotHasKey($code, $documented);
            $documented[$code] = [$outcome, $retryable];
        }

        return $documented;
    }
}
