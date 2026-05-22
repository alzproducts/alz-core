<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\Checkout\Mappers;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Application\Checkout\DTOs\VatReliefDeclarationDTO;

/**
 * Maps {@see BasketSnapshotCommand} application commands to Eloquent attribute arrays.
 *
 * Snapshots are insert-only — no reverse mapping is needed.
 */
final class BasketSnapshotMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toModelAttributes(BasketSnapshotCommand $snapshot): array
    {
        return [
            'ip_address' => $snapshot->ipAddress,
            'user_agent' => $snapshot->userAgent,
            'basket_total' => $snapshot->basketTotal->formatGross(),
            'shipping_method_id' => $snapshot->shippingMethodId,
            'delivery_date' => $snapshot->deliveryDate?->format('Y-m-d'),
            'gift_note' => $snapshot->giftNote,
            'vat_relief' => self::serializeVatRelief($snapshot->vatRelief),
        ];
    }

    /**
     * Serialise the declaration to a snake-case array with null fields stripped,
     * so the persisted JSON reflects only what was actually submitted.
     *
     * @return array<string, mixed>|null
     */
    private static function serializeVatRelief(?VatReliefDeclarationDTO $declaration): ?array
    {
        if ($declaration === null) {
            return null;
        }

        $result = [];
        foreach (self::vatReliefPayload($declaration) as $key => $value) {
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function vatReliefPayload(VatReliefDeclarationDTO $declaration): array
    {
        return [
            'eligible' => $declaration->eligible,
            'name' => $declaration->name,
            'address' => $declaration->address,
            'condition' => $declaration->condition,
            'signed_at' => $declaration->signedAt,
        ];
    }
}
