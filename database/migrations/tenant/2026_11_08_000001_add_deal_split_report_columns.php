<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEAL-SPLIT-REPORT-1 — a deal's money can be shown in the reports under the items inside it
 * (a platter of 900 = biryani 400 + BBQ 500) instead of as one line under the deal's own head.
 *
 * Report-only. Nothing here touches a price, a bill, a payment or the books:
 * - combos.report_by_items       — the deal's choice: false (default) = one rate, exactly as before.
 * - combo_components.report_amount — the part of ONE deal that belongs to this item (Rs).
 * - sales_order_lines.deal_share  — copied from the deal at the moment of sale, so editing the deal
 *   later never moves an old report. Header line = the deal's total of parts; component line = its part.
 *   NULL everywhere for a deal that is not split, and for every tenant with the switch off.
 *
 * All three are additive and nullable/defaulted: every existing row reads exactly as before.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasColumn('combos', 'report_by_items')) {
            $schema->table('combos', function (Blueprint $table) {
                $table->boolean('report_by_items')->default(false)->after('price');
            });
        }

        if (! $schema->hasColumn('combo_components', 'report_amount')) {
            $schema->table('combo_components', function (Blueprint $table) {
                $table->decimal('report_amount', 14, 2)->nullable()->after('quantity');
            });
        }

        if (! $schema->hasColumn('sales_order_lines', 'deal_share')) {
            $schema->table('sales_order_lines', function (Blueprint $table) {
                $table->decimal('deal_share', 14, 2)->nullable()->after('combo_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        foreach ([['sales_order_lines', 'deal_share'], ['combo_components', 'report_amount'], ['combos', 'report_by_items']] as [$table, $column]) {
            if ($schema->hasColumn($table, $column)) {
                $schema->table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
