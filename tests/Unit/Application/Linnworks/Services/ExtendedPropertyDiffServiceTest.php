<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\Services;

use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyUpdateDTO;
use App\Application\Linnworks\Services\ExtendedPropertyDiffService;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ExtendedPropertyDiffService Unit Tests.
 *
 * Tests pure diff logic: create/update/delete resolution from current vs desired state.
 */
#[CoversClass(ExtendedPropertyDiffService::class)]
final class ExtendedPropertyDiffServiceTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | All Creates — Empty Current State
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function empty_current_with_desired_properties_produces_all_creates(): void
    {
        $desired = [
            new DesiredExtendedPropertyDTO('IsDropship', 'true'),
            new DesiredExtendedPropertyDTO('ShippingMethod', 'DHL'),
        ];

        $changeset = ExtendedPropertyDiffService::diff([], $desired);

        $this->assertSame($desired, $changeset->toCreate);
        $this->assertSame([], $changeset->toUpdate);
        $this->assertSame([], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | No Changes — Same Values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function matching_properties_with_same_values_produce_empty_changeset(): void
    {
        $current = [
            $this->makeProperty(1, 'IsDropship', 'true'),
            $this->makeProperty(2, 'ShippingMethod', 'DHL'),
        ];
        $desired = [
            new DesiredExtendedPropertyDTO('IsDropship', 'true'),
            new DesiredExtendedPropertyDTO('ShippingMethod', 'DHL'),
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        $this->assertTrue($changeset->isEmpty());
        $this->assertSame([], $changeset->toCreate);
        $this->assertSame([], $changeset->toUpdate);
        $this->assertSame([], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | Updates — Different Values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function matching_properties_with_different_values_produce_updates(): void
    {
        $current = [
            $this->makeProperty(5, 'ShippingMethod', 'DHL'),
        ];
        $desired = [
            new DesiredExtendedPropertyDTO('ShippingMethod', 'FedEx'),
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        $this->assertSame([], $changeset->toCreate);
        $this->assertCount(1, $changeset->toUpdate);
        $this->assertInstanceOf(ExtendedPropertyUpdateDTO::class, $changeset->toUpdate[0]);
        $this->assertSame(5, $changeset->toUpdate[0]->rowId);
        $this->assertSame('ShippingMethod', $changeset->toUpdate[0]->propertyName);
        $this->assertSame('FedEx', $changeset->toUpdate[0]->propertyValue);
        $this->assertSame([], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | Deletes — Properties in Current but Not in Desired
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function current_properties_not_in_desired_produce_deletes(): void
    {
        $current = [
            $this->makeProperty(10, 'OldProperty', 'stale'),
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, []);

        $this->assertSame([], $changeset->toCreate);
        $this->assertSame([], $changeset->toUpdate);
        $this->assertSame([10], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | Mixed Scenario
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function mixed_scenario_produces_creates_updates_and_deletes(): void
    {
        $current = [
            $this->makeProperty(1, 'Existing', 'old-value'),  // will update
            $this->makeProperty(2, 'ToDelete', 'remove-me'),  // will delete
        ];
        $desired = [
            new DesiredExtendedPropertyDTO('Existing', 'new-value'),  // update
            new DesiredExtendedPropertyDTO('NewProp', 'brand-new'),   // create
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        $this->assertCount(1, $changeset->toCreate);
        $this->assertSame('NewProp', $changeset->toCreate[0]->propertyName);
        $this->assertSame('brand-new', $changeset->toCreate[0]->propertyValue);

        $this->assertCount(1, $changeset->toUpdate);
        $this->assertInstanceOf(ExtendedPropertyUpdateDTO::class, $changeset->toUpdate[0]);
        $this->assertSame(1, $changeset->toUpdate[0]->rowId);
        $this->assertSame('Existing', $changeset->toUpdate[0]->propertyName);
        $this->assertSame('new-value', $changeset->toUpdate[0]->propertyValue);

        $this->assertSame([2], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | Null rowId Skipping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function properties_with_null_row_id_are_skipped_for_update(): void
    {
        $current = [
            $this->makeProperty(null, 'NoRowId', 'old-value'),
        ];
        $desired = [
            new DesiredExtendedPropertyDTO('NoRowId', 'new-value'),
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, $desired);

        // No update because rowId is null
        $this->assertSame([], $changeset->toUpdate);
        $this->assertSame([], $changeset->toCreate);
        $this->assertSame([], $changeset->toDelete);
    }

    #[Test]
    public function properties_with_null_row_id_are_skipped_for_delete(): void
    {
        $current = [
            $this->makeProperty(null, 'NoRowId', 'value'),
        ];

        $changeset = ExtendedPropertyDiffService::diff($current, []);

        // No delete because rowId is null
        $this->assertSame([], $changeset->toDelete);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private static function makeProperty(?int $rowId, string $name, string $value): PurchaseOrderExtendedProperty
    {
        return new PurchaseOrderExtendedProperty(
            rowId: $rowId,
            purchaseId: null,
            addedDateTime: null,
            username: null,
            propertyName: $name,
            propertyValue: $value,
        );
    }
}
