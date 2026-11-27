<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'CREATE FUNCTION shopwired.cor385_fixture_definer() RETURNS int LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$',
        );
    }
};
