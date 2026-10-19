<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-DELIVERY-V2-1 — a name for each WhatsApp number ("Owner", "Manager").
 *
 * Kept BESIDE `report_whatsapp`, not inside it: that column is a plain list of numbers read by the
 * nightly report, both Send buttons and the WhatsApp channel's own normaliser. Turning its entries into
 * objects would change what every one of those readers gets. This map (number => name) is read only by
 * the delivery card, so a tenant without it behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'report_whatsapp_names')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('report_whatsapp_names')->nullable()->after('report_whatsapp');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'report_whatsapp_names')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('report_whatsapp_names'));
        }
    }
};
