<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\Checkout\Repositories;

use App\Application\Checkout\DTOs\BasketRecoveryMatchDTO;
use App\Application\Contracts\Checkout\BasketRecoveryQueryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Database\DatabaseGateway;
use DateMalformedStringException;
use DateTimeImmutable;
use Override;
use ValueError;
use Webmozart\Assert\Assert;

final readonly class BasketRecoveryQueryRepository implements BasketRecoveryQueryInterface
{
    public function __construct(
        private DatabaseGateway $gateway,
    ) {}

    /**
     * @return list<BasketRecoveryMatchDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws MalformedStoredDataException
     */
    #[Override]
    public function getMatches(int $scopeWindowDays, bool $onlyNeedsUpdate): array
    {
        /** @var list<object{basket_total: numeric-string, delivery_date: ?string, gift_note: ?string, vat_relief: ?string, snapshot_created_at: string, order_number: string, match_count: int, multiple_orders_placed_within_timeframe: bool, order_missing_vat_relief: bool, order_missing_gift_note: bool, order_missing_delivery_date: bool, has_missing_data: bool}> $rows */
        $rows = $this->gateway->query(
            fn(): array => $this->gateway->connection()
                ->select(
                    'SELECT * FROM checkout.basket_recovery_matches(?, ?)',
                    [$scopeWindowDays, $onlyNeedsUpdate],
                ),
        );

        return \array_map(static fn(object $row): BasketRecoveryMatchDTO => new BasketRecoveryMatchDTO(
            basketTotal: Money::inclusiveFromString($row->basket_total),
            deliveryDate: $row->delivery_date !== null
                ? self::parseDate($row->delivery_date)
                : null,
            giftNote: $row->gift_note,
            vatRelief: $row->vat_relief !== null
                ? self::decodeVatRelief($row->vat_relief)
                : null,
            snapshotCreatedAt: self::parseTimestamp($row->snapshot_created_at),
            orderNumber: $row->order_number,
            matchCount: $row->match_count,
            multipleOrdersPlacedWithinTimeframe: $row->multiple_orders_placed_within_timeframe,
            orderMissingVatRelief: $row->order_missing_vat_relief,
            orderMissingGiftNote: $row->order_missing_gift_note,
            orderMissingDeliveryDate: $row->order_missing_delivery_date,
            hasMissingData: $row->has_missing_data,
        ), $rows);
    }

    /**
     * @throws MalformedStoredDataException
     */
    private static function parseDate(string $value): DateTimeImmutable
    {
        try {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        } catch (ValueError $e) {
            throw new MalformedStoredDataException(
                source: 'checkout.basket_snapshots.delivery_date',
                reason: 'expected Y-m-d format',
                previous: $e,
            );
        }

        if ($date === false) {
            throw new MalformedStoredDataException(
                source: 'checkout.basket_snapshots.delivery_date',
                reason: 'expected Y-m-d format',
            );
        }

        return $date;
    }

    /**
     * @throws MalformedStoredDataException
     */
    private static function parseTimestamp(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                source: 'checkout.basket_snapshots.created_at',
                reason: 'expected valid timestamp',
                previous: $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeVatRelief(string $json): array
    {
        $decoded = \json_decode($json, true);
        Assert::isMap($decoded);

        return $decoded;
    }
}
