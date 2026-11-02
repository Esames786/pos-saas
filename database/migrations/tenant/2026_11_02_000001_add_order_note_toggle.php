<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-ORDER-NOTE-1 — the order note on the KOT gets its own layout switch.
 *
 * Owner, 2 Nov: a website order's KOT printed the whole website note (payment, location, distance, map link)
 * and nothing in Edit Layout could turn it off. Default ON for every layout, so no tenant's paper changes on
 * deploy; an operator switches it off per branch in Edit Layout.
 *
 * A tiny table (a few rows per tenant); a boolean with a default is an INSTANT change on MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_order_note')) {
                $table->boolean('show_order_note')->default(true)->after('show_bill_barcode');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_order_note')) {
                $table->dropColumn('show_order_note');
            }
        });
    }
};
