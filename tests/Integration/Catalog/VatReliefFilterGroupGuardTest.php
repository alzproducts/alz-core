<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guard test: ensures the "Eligible for VAT Relief" filter group exists with
 * `external_id = 240` and `option_no = 2`.
 *
 * The VAT-relief sync view and jobs depend on these hardcoded IDs. If someone
 * renumbers or deletes this filter group in ShopWired and re-syncs, this test
 * fails on pre-push — preventing broken code from reaching the remote.
 *
 * The filter title is NOT asserted verbatim because it is admin-editable in
 * ShopWired (the live title currently has a trailing `?`). Identification must
 * use the stable IDs instead.
 */
#[CoversNothing]
#[Group('integration')]
final class VatReliefFilterGroupGuardTest extends TestCase
{
    #[Test]
    public function vat_relief_filter_group_exists_with_external_id_240_and_option_no_2(): void
    {
        $row = DB::connection('pgsql')
            ->table('shopwired.filter_groups')
            ->where('external_id', 240)
            ->first();

        $this->assertNotNull(
            $row,
            'Filter group with external_id=240 must exist — the VAT-relief filter sync view depends on it',
        );
        $this->assertSame(2, (int) $row->option_no);
    }
}
