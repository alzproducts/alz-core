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
 * Guard test: ensures the "Shipping Offers" filter group exists with
 * `external_id = 11411` and `option_no = 20`.
 *
 * The Shipping Offers sync view and jobs depend on these hardcoded IDs. If
 * someone renumbers or deletes this filter group in ShopWired and re-syncs,
 * this test fails on pre-push — preventing broken code from reaching the remote.
 *
 * The filter title is NOT asserted verbatim because it is admin-editable in
 * ShopWired. Identification must use the stable IDs instead.
 */
#[CoversNothing]
#[Group('integration')]
final class ShippingOffersFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function shipping_offers_filter_group_exists_with_external_id_11411_and_option_no_20(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('external_id', 11411)
            ->first();

        $this->assertNotNull(
            $row,
            'Filter group with external_id=11411 must exist — the Shipping Offers filter sync view depends on it',
        );
        $this->assertSame(FilterGroupOptionNo::ShippingOffers->value, (int) $row->option_no);
    }
}
