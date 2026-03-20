<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\PricingUpdate\Results;

use App\Application\Shopwired\PricingUpdate\Results\BatchApiResult;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchApiResult::class)]
final class BatchApiResultTest extends TestCase
{
    #[Test]
    public function succeeded_property_hook_returns_count_of_updated_skus(): void
    {
        $result = new BatchApiResult(
            updatedSkus: [
                Sku::fromTrusted('SKU-1'),
                Sku::fromTrusted('SKU-2'),
            ],
            permanentFailures: [],
            temporaryFailures: [],
        );

        self::assertSame(2, $result->succeeded);
    }

    #[Test]
    public function succeeded_is_zero_when_no_updated_skus(): void
    {
        $result = new BatchApiResult(
            updatedSkus: [],
            permanentFailures: [new FailedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'rejected')],
            temporaryFailures: [],
        );

        self::assertSame(0, $result->succeeded);
    }
}
