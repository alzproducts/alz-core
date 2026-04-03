<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: ensures the "Customer Rating" filter group exists with optionNo 15.
 *
 * The rating filter sync view and jobs depend on this hardcoded optionNo.
 * If someone renumbers or deletes this filter group in ShopWired and re-syncs,
 * this test fails on pre-push — preventing broken code from reaching the remote.
 */
#[CoversNothing]
final class CustomerRatingFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function customer_rating_filter_group_exists_with_option_no_15(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('option_no', 15)
            ->first();

        $this->assertNotNull($row, 'Filter group with option_no=15 must exist — the rating filter sync view depends on it');
        $this->assertSame('Customer Rating', $row->title);
    }
}
