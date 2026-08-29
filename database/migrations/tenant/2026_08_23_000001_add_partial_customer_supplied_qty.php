<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-PARTIAL-SUPPLY-1 — one line, split supply.
 *
 * "Chicken Karahi: 10 KG chicken — 5 KG ours, 5 KG the customer's." The flag
 * (is_customer_supplied) keeps meaning what it always meant: the WHOLE material
 * is the customer's. This column exists only for the split case, and NULL means
 * exactly what every existing snapshot already means — additive by construction,
 * no backfill, no reinterpretation of history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('catering_estimate_line_cost_blocks', function (Blueprint $table) {
            $table->decimal('customer_supplied_qty', 12, 4)->nullable()->after('is_customer_supplied');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_estimate_line_cost_blocks', function (Blueprint $table) {
            $table->dropColumn('customer_supplied_qty');
        });
    }
};
