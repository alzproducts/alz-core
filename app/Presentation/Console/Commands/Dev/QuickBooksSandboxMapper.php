<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use LogicException;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\Facades\FacadeHelper;

/**
 * The sandbox company's fixed names, and the payloads that set it up.
 */
final readonly class QuickBooksSandboxMapper
{
    public const string INVOICE_DOC_NUMBER = 'SYNC-PROOF-INV';

    public const string SALES_RECEIPT_DOC_NUMBER = 'SYNC-PROOF-SR';

    public const string CUSTOMER_NAME = 'Sync Proof Customer';

    /**
     * QBO allows a Term or PaymentMethod name 31 characters.
     */
    public static function nameFor(MappingKey $key): string
    {
        return match ($key) {
            MappingKey::TaxStandard => '20.0% S',
            MappingKey::TaxZero => '0.0% Z',
            MappingKey::TaxEuGoods => '0.0% ECG',
            MappingKey::TaxEuShipping => '0.0% ECS',
            MappingKey::PoNumberField => 'P.O. Number',
            MappingKey::TransactionIdField => 'Transaction ID',
            MappingKey::DepositAccountPayPal => 'Sync Test PayPal',
            MappingKey::DepositAccountCardOpayo => 'Sync Test Card Opayo',
            MappingKey::DepositAccountShopWiredPayments => 'Sync Test ShopWired Payments',
            MappingKey::PaymentMethodPayPal => 'Sync Test PP',
            MappingKey::PaymentMethodCardOpayo => 'Sync Test Opayo',
            MappingKey::PaymentMethodShopWiredPayments => 'Sync Test SWP',
            MappingKey::Department => 'Sync Test Department',
            MappingKey::SalesClass => 'Sync Test Class',
            MappingKey::DefaultTerms => 'Sync Test Terms',
        };
    }

    public static function isMatchOnly(MappingKey $key): bool
    {
        return \in_array($key->entity(), [MappingEntity::TaxCode, MappingEntity::CustomField], true);
    }

    /**
     * QBO refuses an Account without a type and a Term without a due rule.
     */
    public static function mappingEntity(MappingEntity $entity, string $name): IPPIntuitEntity
    {
        return self::build($entity->value, match ($entity) {
            MappingEntity::Account => ['Name' => $name, 'AccountType' => 'Bank'],
            MappingEntity::Term => ['Name' => $name, 'DueDays' => 30],
            MappingEntity::PaymentMethod, MappingEntity::Department, MappingEntity::SalesClass => ['Name' => $name],
            MappingEntity::TaxCode, MappingEntity::CustomField => throw new LogicException('This entity is never created'),
        });
    }

    public static function item(string $sku, string $incomeAccountId): IPPIntuitEntity
    {
        return self::build('Item', [
            'Name' => $sku,
            'Type' => 'NonInventory',
            'IncomeAccountRef' => ['value' => $incomeAccountId],
        ]);
    }

    // intentional: carries these two preferences alone; writing a custom field slot faults.
    public static function preferences(string $id, string $syncToken): IPPIntuitEntity
    {
        return self::build('Preferences', [
            'Id' => $id,
            'SyncToken' => $syncToken,
            'sparse' => 'true',
            'SalesFormsPrefs' => ['CustomTxnNumbers' => 'true', 'AllowShipping' => 'true'],
        ]);
    }

    /**
     * intentional: never the fault's message, which is Intuit's text and can hold customer names.
     */
    public static function faultText(?QuickBooksFault $fault): string
    {
        return $fault === null ? 'no fault' : "{$fault->kind->value}, code " . ($fault->code ?? 'none');
    }

    /**
     * @param non-empty-array<string, mixed> $payload
     */
    private static function build(string $name, array $payload): IPPIntuitEntity
    {
        // intentional: the helper behind the SDK's facades, as PaymentMethod has no facade of its own.
        $built = FacadeHelper::reflectArrayToObject($name, $payload);

        if (!$built instanceof IPPIntuitEntity) {
            throw new LogicException('The SDK did not build the entity');
        }

        return $built;
    }
}
