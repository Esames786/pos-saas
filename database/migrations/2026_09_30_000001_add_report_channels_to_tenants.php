<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the tenant-wide default for report delivery.
 *
 * The scheduled report has no branch: `report_schedules` carries no branch_id, so a nightly report
 * covers the whole tenant. That is why the tenant needs its own setting and not just the branches —
 * without it the cron would have nowhere to read the channel from.
 *
 * Default ['email'] is the behaviour every live tenant has today, so a tenant that is never touched
 * keeps sending exactly what it sends now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'report_channels')) {
                $table->json('report_channels')->nullable()->after('owner_email');
            }
            if (! Schema::hasColumn('tenants', 'report_whatsapp')) {
                $table->json('report_whatsapp')->nullable()->after('owner_email');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            foreach (['report_channels', 'report_whatsapp'] as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
