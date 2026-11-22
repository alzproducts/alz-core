<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use JsonException;
use UnexpectedValueException;

/**
 * The order shape bulk orders are drawn from: the exported orders profile, read only from the path an operator
 * passes, or synthetic defaults. Holds no names, emails or addresses; the profile has none.
 */
final readonly class OrderProfile
{
    /**
     * @param Weighted<PaymentMethod> $paymentMethods
     * @param Weighted<int> $linesPerOrder
     * @param Weighted<int> $quantities
     * @param Weighted<LineSpec> $priceTuples SKU-less line shapes: price, price VAT and VAT rate
     * @param Weighted<string> $countries
     */
    public function __construct(
        public Weighted $paymentMethods,
        public Weighted $linesPerOrder,
        public Weighted $quantities,
        public Weighted $priceTuples,
        public Weighted $countries,
        public float $cancelledShare,
        public float $refundedShare,
        public float $partRefundedShare,
        public float $vatReliefShare,
        public float $zeroRatedShare,
    ) {}

    public static function synthetic(): self
    {
        return new self(
            paymentMethods: new Weighted([
                [PaymentMethod::Card, 60],
                [PaymentMethod::PayPal, 25],
                [PaymentMethod::Admin, 13],
                [PaymentMethod::Credit, 1],
                [PaymentMethod::Unknown, 1],
            ]),
            linesPerOrder: new Weighted([[1, 60], [2, 25], [3, 10], [4, 5]]),
            quantities: new Weighted([[1, 80], [2, 15], [3, 5]]),
            priceTuples: Weighted::suppressedOnly(),
            countries: new Weighted([['United Kingdom', 90], ['Ireland', 4], ['France', 4], ['United States', 2]]),
            cancelledShare: 0.03,
            refundedShare: 0.03,
            partRefundedShare: 0.03,
            vatReliefShare: 0.02,
            zeroRatedShare: 0.05,
        );
    }

    /**
     * @throws JsonException
     */
    public static function fromFile(string $path): self
    {
        $json = \is_file($path) ? \file_get_contents($path) : false;

        if ($json === false) {
            throw new UnexpectedValueException('Orders profile file is unreadable');
        }

        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new UnexpectedValueException('Orders profile must be a JSON object');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $profile = new ProfileReader($data);

        return new self(
            paymentMethods: $profile->weighted('payment_method', 'orders', self::paymentMethod(...)),
            linesPerOrder: $profile->weighted('lines_per_order', 'orders', self::lineCount(...)),
            quantities: $profile->weighted('qty', 'lines', self::quantity(...)),
            priceTuples: $profile->weighted('price_tuples', 'lines', self::priceTuple(...)),
            countries: $profile->weighted('destination_class', 'orders', self::country(...)),
            cancelledShare: $profile->share('cancelled'),
            refundedShare: $profile->share('refunded'),
            partRefundedShare: $profile->share('part_refunded'),
            vatReliefShare: $profile->share('vat_relief'),
            zeroRatedShare: $profile->share('zero_rated_line'),
        );
    }

    /**
     * A null or unrecognised grouped method is dropped: every seeded order needs a raw method (COR-299/D2).
     *
     * @param array<mixed> $row
     */
    private static function paymentMethod(array $row): ?PaymentMethod
    {
        return \is_string($row['payment_method'] ?? null) ? PaymentMethod::tryFrom($row['payment_method']) : null;
    }

    /**
     * Zero-line orders are covered by their own edge case; bulk orders always carry lines.
     *
     * @param array<mixed> $row
     */
    private static function lineCount(array $row): ?int
    {
        $count = ProfileReader::number($row['line_count'] ?? null);

        return $count !== null && $count >= 1 ? (int) $count : null;
    }

    /**
     * @param array<mixed> $row
     */
    private static function quantity(array $row): ?int
    {
        $quantity = ProfileReader::number($row['qty'] ?? null);

        return $quantity !== null && $quantity >= 1 ? (int) $quantity : null;
    }

    /**
     * @param array<mixed> $row
     */
    private static function priceTuple(array $row): ?LineSpec
    {
        $price = ProfileReader::number($row['price'] ?? null);
        $priceVat = ProfileReader::number($row['price_vat'] ?? null);
        $vatRate = ProfileReader::number($row['vat_rate'] ?? null);

        if ($price === null || $priceVat === null || $vatRate === null || $price <= 0) {
            return null;
        }

        return new LineSpec('', $price, $priceVat, (int) $vatRate);
    }

    /**
     * @param array<mixed> $row
     */
    private static function country(array $row): ?string
    {
        return match ($row['destination_class'] ?? null) {
            'uk' => 'United Kingdom',
            'ireland' => 'Ireland',
            'rest_of_eu' => 'France',
            'other' => 'United States',
            default => null,
        };
    }
}
