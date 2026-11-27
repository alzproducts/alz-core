<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db = DB::connection('pgsql');
        $definition = $db->selectOne("SELECT pg_get_viewdef('catalog.products_with_changed_ratings'::regclass, true) AS sql");

        $db->statement('DROP VIEW catalog.products_with_changed_ratings');
        $db->statement('CREATE VIEW catalog.products_with_changed_ratings AS ' . $definition->sql);
    }
};
