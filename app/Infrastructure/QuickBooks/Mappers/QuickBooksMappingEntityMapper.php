<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use LogicException;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\Facades\FacadeHelper;

/**
 * A synthetic mapping entity as a QBO create payload. QBO refuses an Account without a type and a Term without a due rule.
 */
final readonly class QuickBooksMappingEntityMapper
{
    private const string ACCOUNT_TYPE = 'Bank';

    private const int TERM_DUE_DAYS = 30;

    public static function toEntity(MappingEntity $entity, string $name): IPPIntuitEntity
    {
        // intentional: the helper behind the SDK's facades, as PaymentMethod has no facade of its own.
        $built = FacadeHelper::reflectArrayToObject($entity->value, self::payload($entity, $name));

        if (!$built instanceof IPPIntuitEntity) {
            throw new LogicException('The SDK did not build a mapping entity');
        }

        return $built;
    }

    /**
     * @return non-empty-array<string, int|string>
     */
    private static function payload(MappingEntity $entity, string $name): array
    {
        return match ($entity) {
            MappingEntity::Account => ['Name' => $name, 'AccountType' => self::ACCOUNT_TYPE],
            MappingEntity::Term => ['Name' => $name, 'DueDays' => self::TERM_DUE_DAYS],
            MappingEntity::PaymentMethod, MappingEntity::Department, MappingEntity::SalesClass => ['Name' => $name],
            MappingEntity::TaxCode, MappingEntity::CustomField => throw new LogicException('This entity is never created'),
        };
    }
}
