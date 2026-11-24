<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncOutcomeResolver::class)]
#[CoversClass(SyncFailureDTO::class)]
final class SyncOutcomeResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{SyncFailureDTO|null, SyncReasonCode|null}>
     */
    public static function causes(): iterable
    {
        yield 'no payment method' => [SyncOutcomeResolver::forDefect(SourceOrderDefect::PaymentMethodMissing, null), SyncReasonCode::MappingMissing];
        yield 'no SKU' => [SyncOutcomeResolver::forDefect(SourceOrderDefect::SkuMissing, null), SyncReasonCode::ItemMissing];
        yield 'invalid rate' => [SyncOutcomeResolver::forDefect(SourceOrderDefect::VatRateInvalid, null), SyncReasonCode::TaxRateUnsupported];
        yield 'invalid quantity' => [SyncOutcomeResolver::forDefect(SourceOrderDefect::QuantityInvalid, SyncReasonCode::ItemMissing), SyncReasonCode::QboValidation];
        yield 'no lines' => [SyncOutcomeResolver::forBuildFailure(FailReason::ZeroLines, null), SyncReasonCode::ZeroLines];
        yield 'unsupported rate' => [SyncOutcomeResolver::forBuildFailure(FailReason::TaxRateUnsupported, null), SyncReasonCode::TaxRateUnsupported];
        yield 'unavailable' => [SyncOutcomeResolver::forUnavailable(null), SyncReasonCode::QboUnavailable];
        yield 'unavailable over a stored failure' => [SyncOutcomeResolver::forUnavailable(SyncReasonCode::ItemMissing), SyncReasonCode::QboUnavailable];
        yield 'missing item' => [SyncOutcomeResolver::forMissingItem(), SyncReasonCode::ItemMissing];
        yield 'no missing refs' => [SyncOutcomeResolver::forMissingRefs([]), null];
        yield 'missing mapping' => [SyncOutcomeResolver::forMissingRefs(['terms']), SyncReasonCode::MappingMissing];
        yield 'missing mapping and item' => [SyncOutcomeResolver::forMissingRefs(['terms', 'item:A']), SyncReasonCode::ItemMissing];
        yield 'GBP customer' => [SyncOutcomeResolver::forCustomer(self::customer('GBP')), null];
        yield 'customer without a currency' => [SyncOutcomeResolver::forCustomer(self::customer(null)), null];
        yield 'USD customer' => [SyncOutcomeResolver::forCustomer(self::customer('USD')), SyncReasonCode::QboValidation];
        yield 'unreadable currency' => [SyncOutcomeResolver::forCustomer(self::customer('')), SyncReasonCode::QboValidation];
        yield 'created' => [SyncOutcomeResolver::forBatch(BatchItemOutcomeResult::created('1')), null];
        yield 'rate limited' => [SyncOutcomeResolver::forBatch(BatchItemOutcomeResult::rateLimited(1)), SyncReasonCode::QboUnavailable];
        yield 'ambiguous' => [SyncOutcomeResolver::forBatch(BatchItemOutcomeResult::ambiguous()), SyncReasonCode::OutcomeUnknown];
        foreach ([CustomerFailureKind::Inactive, CustomerFailureKind::NameClash] as $kind) {
            yield "customer {$kind->value}" => [SyncOutcomeResolver::forCustomer(CustomerResolutionDTO::failed('A', $kind)), SyncReasonCode::QboValidation];
        }
        foreach (QuickBooksFaultKind::cases() as $kind) {
            $fault = new QuickBooksFault($kind, '1', null, 'Said by QBO');
            $code = $kind === QuickBooksFaultKind::ClosedPeriod ? SyncReasonCode::ClosedPeriod : SyncReasonCode::QboValidation;

            yield "batch fault {$kind->value}" => [SyncOutcomeResolver::forBatch(BatchItemOutcomeResult::faulted($fault)), $code];
            yield "customer fault {$kind->value}" => [
                SyncOutcomeResolver::forCustomer(CustomerResolutionDTO::failed('A', CustomerFailureKind::Faulted, $fault)),
                $code,
            ];
        }
    }

    #[Test]
    #[DataProvider('causes')]
    public function a_cause_has_its_code(?SyncFailureDTO $failure, ?SyncReasonCode $code): void
    {
        self::assertSame($code, $failure?->code);
        self::assertSame($code, $failure?->loggedCode);
    }

    #[Test]
    public function a_fault_carries_its_text_and_itself(): void
    {
        $fault = new QuickBooksFault(QuickBooksFaultKind::Validation, '6000', null, 'Said by QBO');

        self::assertEquals(
            new SyncFailureDTO(SyncReasonCode::QboValidation, SyncReasonCode::QboValidation, 'Said by QBO', $fault),
            SyncOutcomeResolver::forBatch(BatchItemOutcomeResult::faulted($fault)),
        );
    }

    #[Test]
    public function a_currency_failure_names_the_currency_or_says_it_is_unreadable(): void
    {
        self::assertStringContainsString('USD', SyncOutcomeResolver::forCustomer(self::customer('USD'))->message ?? '');
        self::assertStringContainsString('could not be read', SyncOutcomeResolver::forCustomer(self::customer(''))->message ?? '');
    }

    #[Test]
    public function a_stop_before_the_batch_create_keeps_a_stored_unknown_outcome(): void
    {
        foreach ([
            SyncOutcomeResolver::forDefect(SourceOrderDefect::PaymentMethodMissing, SyncReasonCode::OutcomeUnknown),
            SyncOutcomeResolver::forBuildFailure(FailReason::ZeroLines, SyncReasonCode::OutcomeUnknown),
            SyncOutcomeResolver::forUnavailable(SyncReasonCode::OutcomeUnknown),
        ] as $failure) {
            self::assertSame(SyncReasonCode::OutcomeUnknown, $failure->code);
            self::assertNotSame(SyncReasonCode::OutcomeUnknown, $failure->loggedCode);
            self::assertStringContainsString($failure->loggedCode->value, $failure->message);
        }
    }

    private static function customer(?string $currency): CustomerResolutionDTO
    {
        return CustomerResolutionDTO::matched('A', new CustomerMatchDTO('1', 'A', null, $currency));
    }
}
