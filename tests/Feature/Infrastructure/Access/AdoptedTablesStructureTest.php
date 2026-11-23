<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tables adopted from alz-admin have production's structure.
 *
 * intentional: no DatabaseTransactions — the query empties search_path for its transaction, which must end with it.
 */
#[CoversNothing]
final class AdoptedTablesStructureTest extends TestCase
{
    #[Test]
    public function the_adopted_tables_match_the_committed_reference(): void
    {
        $structure = DB::scalar(File::get(\database_path('structure/adopted_tables.sql')));

        $this->assertIsString($structure);
        $this->assertSame(
            \explode("\n", \mb_rtrim(File::get(\database_path('structure/adopted_tables.txt')))),
            \explode("\n", $structure),
        );
    }
}
