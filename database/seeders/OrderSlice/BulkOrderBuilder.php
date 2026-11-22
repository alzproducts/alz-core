<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Application\Enums\FixtureSku;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use Database\Seeders\ProductSlice\Builders\SeedRandom;
use Database\Seeders\ProductSlice\SeedIds;
use Faker\Generator;

/**
 * Bulk orders shaped by the profile's distributions and shares. Identities come from the seeded Faker only.
 * No bulk order is an edited pair; the edited edge case covers that rule.
 */
final readonly class BulkOrderBuilder
{
    public const int COUNT = 200;

    /** Bulk slots start above every edge-case slot. */
    private const int FIRST_SLOT = 1_001;

    private const int SHARE_SCALE = 1_000_000;

    private const int MAX_TUPLE_REDRAWS = 20;

    private const array CARD_METHODS = [
        RawPaymentMethod::OpayoHosted,
        RawPaymentMethod::OpayoDirect,
        RawPaymentMethod::OpayoForm,
        RawPaymentMethod::SagepayDirect,
        RawPaymentMethod::SagepayForm,
        RawPaymentMethod::SagepayHosted,
        RawPaymentMethod::ShopWiredPayments,
    ];

    private const array SHIPPING_NET = [0.0, 3.99, 4.99, 4.99, 6.95];

    private SeedRandom $random;

    public function __construct(
        private Generator $faker,
    ) {
        $this->random = new SeedRandom($faker);
    }

    /**
     * @return list<OrderSpec>
     */
    public function build(OrderProfile $profile): array
    {
        $orders = [];

        for ($index = 0; $index < self::COUNT; $index++) {
            $orders[] = $this->order($profile, self::FIRST_SLOT + $index, $index + 1);
        }

        return $orders;
    }

    private function order(OrderProfile $profile, int $slot, int $number): OrderSpec
    {
        $method = $this->rawMethod($this->draw($profile->paymentMethods) ?? PaymentMethod::Card);
        $hasVatRelief = $this->chance($profile->vatReliefShare);
        $lines = $this->lines($profile, $hasVatRelief);
        $shipping = $this->random->pick(self::SHIPPING_NET);
        [$status, $refunds] = $this->status($profile, OrderTotals::baseline($lines, $shipping));

        return new OrderSpec(
            externalId: SeedIds::ORDER_BASE + $slot * 2,
            reference: SeedIds::ORDER_REFERENCE_BASE + $slot,
            placedAt: $this->random->int(OrderMonth::FIRST, OrderMonth::LAST),
            paymentMethod: $method,
            status: $status,
            customer: $this->customer($profile, $slot, $number, $method),
            lines: $lines,
            shippingNet: $shipping,
            originalShippingNet: $shipping,
            hasVatRelief: $hasVatRelief,
            refunds: $refunds,
        );
    }

    private function rawMethod(PaymentMethod $grouped): RawPaymentMethod
    {
        return match ($grouped) {
            PaymentMethod::Admin => RawPaymentMethod::AdminOrder,
            PaymentMethod::PayPal => RawPaymentMethod::PayPal,
            PaymentMethod::Credit => RawPaymentMethod::Credit,
            PaymentMethod::Unknown => RawPaymentMethod::Offline,
            PaymentMethod::Card => $this->random->pick(self::CARD_METHODS),
        };
    }

    /**
     * Relief orders are all zero-rated lines. Otherwise the zero-rated share is drawn conditionally, so the overall
     * zero-rated share (relief orders included) matches the profile's.
     *
     * @return list<LineSpec>
     */
    private function lines(OrderProfile $profile, bool $hasVatRelief): array
    {
        $remainingShare = 1.0 - $profile->vatReliefShare;
        $zeroRated = $hasVatRelief || ($remainingShare > 0.0 && $this->chance(($profile->zeroRatedShare - $profile->vatReliefShare) / $remainingShare));
        $count = $this->draw($profile->linesPerOrder) ?? 1;
        $lines = [];

        for ($line = 0; $line < $count; $line++) {
            $lines[] = $this->line($profile, $hasVatRelief || ($zeroRated && $line === 0));
        }

        return $lines;
    }

    private function line(OrderProfile $profile, bool $zeroRated): LineSpec
    {
        $tuple = $this->tuple($profile, $zeroRated);
        $quantity = $this->draw($profile->quantities) ?? $this->random->int(1, 3);
        $sku = $this->random->pick(FixtureSku::values());

        return new LineSpec($sku, $tuple->price, $tuple->priceVat, $tuple->vatRate, $quantity);
    }

    /**
     * Rejection-samples a profile tuple of the wanted rate class; suppressed mass or no match gives a synthetic one.
     */
    private function tuple(OrderProfile $profile, bool $zeroRated): LineSpec
    {
        for ($attempt = 0; $attempt < self::MAX_TUPLE_REDRAWS; $attempt++) {
            $tuple = $this->draw($profile->priceTuples);

            if ($tuple === null) {
                break;
            }

            if (($tuple->vatRate === 0) === $zeroRated) {
                return $tuple;
            }
        }

        $price = $this->random->money(100, 6_000);

        return $zeroRated ? LineSpec::zeroRated('', $price) : LineSpec::standard('', $price);
    }

    /**
     * @return array{OrderStatusType, list<float>}
     */
    private function status(OrderProfile $profile, float $baseline): array
    {
        $refundDraw = $this->fraction();

        if ($refundDraw < $profile->refundedShare) {
            return [OrderStatusType::Refunded, [$baseline]];
        }

        if ($refundDraw < $profile->refundedShare + $profile->partRefundedShare) {
            $refund = \round($baseline * $this->random->int(10, 90) / 100, 2);

            return [OrderStatusType::PartRefunded, [\max(0.01, $refund)]];
        }

        if ($this->chance($profile->cancelledShare)) {
            return [OrderStatusType::Cancelled, []];
        }

        return [$this->random->pick([OrderStatusType::Processing, OrderStatusType::Dispatched, OrderStatusType::Dispatched]), []];
    }

    private function customer(OrderProfile $profile, int $slot, int $number, RawPaymentMethod $method): Customer
    {
        $hasCompany = $method === RawPaymentMethod::AdminOrder && $this->chance(0.5);

        return new Customer(
            id: SeedIds::CUSTOMER_BASE + $slot,
            name: $this->faker->name(),
            email: 'customer' . $number . '@example.com',
            addressLine1: $this->faker->streetAddress(),
            city: $this->faker->city(),
            postcode: $this->faker->postcode(),
            country: $this->draw($profile->countries) ?? 'United Kingdom',
            company: $hasCompany ? $this->faker->company() : null,
        );
    }

    /**
     * @template T
     *
     * @param Weighted<T> $weighted
     *
     * @return T|null null when the draw lands in suppressed mass or the distribution is empty
     */
    private function draw(Weighted $weighted): mixed
    {
        $total = $weighted->totalWeight();

        if ($total === 0) {
            return null;
        }

        $target = $this->random->int(1, $total);

        foreach ($weighted->entries as [$value, $weight]) {
            $target -= $weight;

            if ($target <= 0) {
                return $value;
            }
        }

        return null;
    }

    private function chance(float $share): bool
    {
        return $this->fraction() < $share;
    }

    private function fraction(): float
    {
        return $this->random->int(0, self::SHARE_SCALE - 1) / self::SHARE_SCALE;
    }
}
