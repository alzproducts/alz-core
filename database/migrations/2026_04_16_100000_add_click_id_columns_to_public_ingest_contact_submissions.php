<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds additional click ID attribution columns to contact_submissions.
 *
 * Adds msclkid (Microsoft Ads), fbclid (Meta Ads), gclsrc, wbraid, gbraid.
 * Partial B-tree indexes on msclkid and fbclid for conversion tracking.
 * gclsrc/wbraid/gbraid are not indexed - flag values or rarely populated.
 *
 * Deploy-safe: purely additive migration, no breaking changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('public_ingest.contact_submissions', static function (Blueprint $table): void {
            $table->string('gclsrc', 255)->nullable()->after('gclid');
            $table->string('wbraid', 255)->nullable()->after('gclsrc');
            $table->string('gbraid', 255)->nullable()->after('wbraid');
            $table->string('msclkid', 255)->nullable()->after('gbraid');
            $table->string('fbclid', 255)->nullable()->after('msclkid');
        });

        DB::statement("COMMENT ON COLUMN public_ingest.contact_submissions.msclkid IS 'Internal: Microsoft Ads click ID for conversion attribution'");
        DB::statement("COMMENT ON COLUMN public_ingest.contact_submissions.fbclid IS 'Internal: Meta Ads click ID for conversion attribution'");

        DB::statement('CREATE INDEX idx_contact_submissions_msclkid ON public_ingest.contact_submissions(msclkid) WHERE msclkid IS NOT NULL');
        DB::statement('CREATE INDEX idx_contact_submissions_fbclid ON public_ingest.contact_submissions(fbclid) WHERE fbclid IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public_ingest.idx_contact_submissions_msclkid');
        DB::statement('DROP INDEX IF EXISTS public_ingest.idx_contact_submissions_fbclid');

        Schema::table('public_ingest.contact_submissions', static function (Blueprint $table): void {
            $table->dropColumn(['gclsrc', 'wbraid', 'gbraid', 'msclkid', 'fbclid']);
        });
    }
};
