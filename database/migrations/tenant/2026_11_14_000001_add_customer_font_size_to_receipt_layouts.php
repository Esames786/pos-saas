<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RECEIPT-CUSTOMER-SIZE-1 — the bill's Customer / Phone / Deliver to lines get their own size.
 *
 * Those three lines always printed at normal size (only the item rows and the totals followed the
 * layout's font). NULL = exactly today's bill, so no tenant's paper or preview changes on deploy —
 * the value only takes effect once someone types it in Edit Layout (Receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'customer_font_size')) {
                $table->unsignedTinyInteger('customer_font_size')->nullable()->after('time_font_size');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'customer_font_size')) {
                $table->dropColumn('customer_font_size');
            }
        });
    }
};
