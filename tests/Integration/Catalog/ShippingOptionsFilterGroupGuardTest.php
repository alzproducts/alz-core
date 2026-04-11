<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Infrastructure\Shopwired\Enums\FilterGroupOptionNo;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: ensures the "Shipping Options" filter group exists with
 * `external_id = 11412` and `option_no = 25`.
 *
 * The Shipping Options sync view and jobs depend on these hardcoded IDs. If
 * someone renumbers or deletes this filter group in ShopWired and re-syncs,
 * this test fails on pre-push — preventing broken code from reaching the remote.
 *
 * The filter title is NOT asserted verbatim because it is admin-editable in
 * ShopWired. Identification must use the stable IDs instead.
 *
 * NOTE: This test requires `shopwired.filter_groups` to contain the row for
 * external_id = 11412. Run the ShopWired filter-group sync first. Do NOT
 * hand-insert the row — that masks a broken seed pipeline.
 */
#[CoversNothing]
#[Group('integration')]
final class ShippingOptionsFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function shipping_options_filter_group_exists_with_external_id_11412_and_option_no_25(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('external_id', 11412)
            ->first();

        $this->assertNotNull(
            $row,
            'Filter group with external_id=11412 must exist — the Shipping Options filter sync view depends on it',
        );
        $this->assertSame(FilterGroupOptionNo::ShippingOptions->value, (int) $row->option_no);
    }
}
