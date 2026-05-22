<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\Mappers;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Presentation\Http\Checkout\DTOs\BasketSnapshotRequestDTO;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * Builds a {@see BasketSnapshotCommand} application command from the HTTP request.
 *
 * Owns all HTTP-extraction concerns: parses the validated DTO, captures IP and
 * user agent server-side (never trusted from client input), and constructs the
 * Money VO. `delivery_date` is DTO-validated as `Y-m-d`, so the date catch
 * defends against validation bypass only.
 */
final readonly class BasketSnapshotMapper
{
    /**
     * @throws MalformedStoredDataException When delivery_date bypassed DTO validation and is unparseable
     */
    public function toCommand(Request $request): BasketSnapshotCommand
    {
        $data = BasketSnapshotRequestDTO::from($request);

        return new BasketSnapshotCommand(
            ipAddress: $request->ip() ?? '0.0.0.0',
            userAgent: (string) $request->userAgent(),
            basketTotal: Money::inclusiveFromString($data->basketTotal),
            shippingMethodId: $data->shippingMethodId,
            deliveryDate: self::parseDeliveryDate($data->deliveryDate),
            giftNote: $data->giftNote,
            vatRelief: $data->vatRelief?->toDeclaration(),
        );
    }

    /**
     * Parse the ISO date validated by the DTO.
     *
     * The DTO's `#[DateFormat('Y-m-d')]` already rejects malformed input, so a parse
     * failure here would indicate a validation bypass — translate to a domain exception
     * that maps to a meaningful HTTP response. Mirrors the date-parsing pattern in
     * SubmitQuoteConversionUseCase::buildCommand().
     *
     * @throws MalformedStoredDataException When the date string is not parseable
     */
    private static function parseDeliveryDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException $e) {
            throw new MalformedStoredDataException(
                'BasketSnapshotRequest',
                'delivery_date must be a parseable date string',
                previous: $e,
            );
        }
    }
}
