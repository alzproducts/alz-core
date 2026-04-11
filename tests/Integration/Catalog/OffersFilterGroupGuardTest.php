<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Infrastructure\Shopwired\Enums\FilterGroupOptionNo;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: ensures the "Offers" filter group exists with
 * `external_id = 10073` and `option_no = 14`.
 *
 * The Offers sync view and jobs depend on these hardcoded IDs. If someone
 * renumbers or deletes this filter group in ShopWired and re-syncs, this test
 * fails on pre-push — preventing broken code from reaching the remote.
 *
 * The filter title is NOT asserted verbatim because it is admin-editable in
 * ShopWired. Identification must use the stable IDs instead.
 */
#[CoversNothing]
final class OffersFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function offers_filter_group_exists_with_external_id_10073_and_option_no_14(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('external_id', 10073)
            ->first();

        $this->assertNotNull(
            $row,
            'Filter group with external_id=10073 must exist — the Offers filter sync view depends on it',
        );
        $this->assertSame(FilterGroupOptionNo::Offers->value, (int) $row->option_no);
    }
}
