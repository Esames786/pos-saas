<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-DEAL-HEADING-1 — a KOT layout switch: "Deal name on top, its items under it".
 *
 * Owner, 11 Nov (a client's ask): on the KOT a deal should print its name ONCE, a little bigger than
 * an item, with its items smaller underneath and no rule between them — not every item boxed with
 * "(Deal name)" repeated under each one. Category routing does not change.
 *
 * Default OFF for every layout, so no tenant's paper changes on deploy; it is switched on per branch
 * in Edit Layout. A tiny table (a few rows per tenant); a boolean with a default is an INSTANT change
 * on MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_deal_as_heading')) {
                $table->boolean('show_deal_as_heading')->default(false)->after('show_order_note');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_deal_as_heading')) {
                $table->dropColumn('show_deal_as_heading');
            }
        });
    }
};
