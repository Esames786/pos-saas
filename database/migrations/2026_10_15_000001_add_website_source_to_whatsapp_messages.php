<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONLINE-ORDERING-1 (Phase 4) — website order ke WhatsApp messages bhi usi register me (`source = website`),
 * usi rate par, usi mahana usage invoice me — WhatsApp ka kharcha 1% commission se alag line.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection('master')->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::connection('master')->statement(
                "ALTER TABLE whatsapp_messages MODIFY source ENUM('schedule','manual','backfill','website') NOT NULL DEFAULT 'schedule'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection('master')->getDriverName(), ['mysql', 'mariadb'], true)
            && ! DB::connection('master')->table('whatsapp_messages')->where('source', 'website')->exists()) {
            DB::connection('master')->statement(
                "ALTER TABLE whatsapp_messages MODIFY source ENUM('schedule','manual','backfill') NOT NULL DEFAULT 'schedule'"
            );
        }
    }
};
